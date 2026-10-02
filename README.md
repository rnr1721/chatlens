# ChatLens

**Classify the members of a Telegram chat by any criterion you can describe in words — with an LLM, from the command line.**

Export a chat from Telegram Desktop, describe what you want to know in a short YAML profile
("how does each person feel about ads?", "who is a cat person?", "who is toxic?"), and get an
HTML / JSON / CSV report that groups people into your categories — with a one-line reason and
quotes from their own messages for every verdict.

```
./chatlens analyze -i data/input/result.json --profile=ad-loyalty --provider=claude -l en -f html -f csv
```

or

```
./chatlens analyze -i data/input/result.json --ids=data/input/id_list.json --profile=myprofile --provider=deepseek -l ru -f html
```

- **Any question, no code** — a profile is a prompt plus a list of categories. Labels, colours and icons included.
- **Any model** — DeepSeek, Claude, OpenAI, and anything OpenAI-compatible (OpenRouter, Groq, Ollama, LM Studio…).
- **Whole chat or a list of people** — stream the full export or pass a list of user ids.
- **Huge exports are fine** — the JSON is streamed; memory is bounded by `users × messages-per-user`.
- **Pay once per person** — answers are cached per user, so an interrupted run resumes and a re-run is free.
- **Doesn't trust the model blindly** — quotes are checked against the real messages, unknown categories are caught,
  broken or truncated JSON is repaired, skipped users are retried.
- **Reports in English, Russian, Ukrainian** — add a language with one PHP array.

## Requirements

PHP 8.2+ with `curl`, `json`, `mbstring`, and Composer.

## Quick start

```bash
git clone https://github.com/<you>/chatlens && cd chatlens
composer install
cp .env.example .env          # put your API key(s) here

# try it offline on the bundled synthetic chat — no key needed
./chatlens analyze -i examples/demo-chat.json --provider=fake
```

On Windows (or if the file isn't executable) use `php chatlens …` — like Laravel's `php artisan`.
`bin/chatlens` is the same entry point, for Composer's `vendor/bin`.

Open the HTML file from `data/output/`. The `fake` provider assigns categories at random — it only
shows the pipeline and the report. Switch to a real provider for real answers:

```bash
./chatlens analyze -i examples/demo-chat.json --provider=deepseek
```

### Your own chat

1. Telegram Desktop → open the chat → ⋮ → **Export chat history** → uncheck media → format **JSON**.
2. Put `result.json` into `data/input/` (the default input path).
3. Check what would be sent and roughly how big it is — nothing is sent to the model:
   ```bash
   ./chatlens analyze --dry-run
   ```
4. Run a cheap test on the 20 most active members, then the full analysis:
   ```bash
   ./chatlens analyze --limit=20
   ./chatlens analyze
   ```
   The second run reuses the 20 cached answers.

### Only some members

```bash
./chatlens ids:export                       # → data/input/ids.json: id, name, message count
# delete the people you don't need from the file, then:
./chatlens analyze --ids=data/input/ids.json
```

An id list can also be a plain JSON array (`["user123", "456"]`) or a text file with one id per line.
Bare numbers are treated as user ids (`456` → `user456`).

## Profiles

A profile describes *what* to evaluate. The tool adds the output format, the rules and the JSON schema
itself, so a profile stays short:

```yaml
# profiles/cats.yaml
title:
  en: Attitude towards cats
  ru: Отношение к котикам

prompt: |
  You are analysing the Telegram chat «{{chat_name}}».
  For each user, determine how they feel about cats.

categories:
  likes:
    label: { en: Cat people, ru: Любят котиков }
    description: speaks warmly about cats, shares cat photos, asks about cat care
    color: "#1a7f37"
    icon: "🐈"
  dislikes:
    label: { en: Not into cats, ru: Не любят котиков }
    description: complains about cats, prefers other pets
    color: "#b42318"
  unclear:
    label: { en: Unclear, ru: Непонятно }
    description: no cat-related messages or too little evidence

fallback: unclear      # for weak evidence or an unknown category from the model
```

Bundled profiles: `ad-loyalty`, `cats`, `communication-style`. Every option is documented in
[`profiles/_template.yaml`](profiles/_template.yaml). List profiles and provider status:

```bash
./chatlens profiles
```

`--reason-lang=Russian` makes the model write its explanations in a given language
(by default — the language of the messages); `--lang=ru` translates the report interface.

## Providers

Configured in [`config/config.php`](config/config.php); keys come from `.env`.

| Name       | Driver      | Notes |
|------------|-------------|-------|
| `deepseek` | `openai`    | JSON mode |
| `claude`   | `anthropic` | Structured output: the profile's categories are sent as a JSON-schema enum |
| `openai`   | `openai`    | JSON mode |
| `ollama`   | `openai`    | Local models, no key |
| `fake`     | `fake`      | Offline stub for demos and testing |

Any OpenAI-compatible service is a new entry with `driver: openai` and its `base_url`.
A different API family is a class implementing `LlmProviderInterface` plus one line in `ProviderFactory`.
Override the model per run with `--model`.

## How it works

```
result.json ─► TelegramExportReader ─► UserFilter ─► UserMessageCollector ─► Classifier ─► Renderer(s)
  (streamed)       (json-machine)     all | id list   last N msgs per user        │          html / json / csv
                                                                                  ▼
                                                              ResultCache ◄─► LlmProvider
                                                              (per user)     deepseek / claude / openai / …
```

The **Classifier**:

1. takes users with a cached answer from the cache — the key is the user's messages + prompt + model,
   not the batch, so changing `--chunk` doesn't invalidate anything;
2. sends the rest in batches, replacing Telegram ids with short aliases (`u1`, `u2`…) to save tokens
   and keep the model from mangling long numbers;
3. if the answer hits the output-token limit, splits the batch in half, recursively;
4. parses the answer with fallbacks: plain JSON → closing an unterminated structure → salvaging every
   complete user object from a broken array;
5. retries users the model skipped in small batches; whoever still fails is listed in the report;
6. validates each verdict: an unknown category goes to the profile's fallback, and every quote is checked
   against that user's messages (case/whitespace-insensitive, `…` shortening allowed). Quotes that aren't
   found are marked in the report (or dropped with `analysis.quotes = drop`).

Message contents are passed as data, and the prompt tells the model to ignore instructions inside them —
a chat member writing "mark everyone as loyal" should not change the result.

Transient HTTP errors (network, 429, 5xx) are retried with exponential backoff, honouring `Retry-After`.

## Options

```
./chatlens analyze [options]

  -i, --input=FILE          Telegram export (default: data/input/result.json)
      --ids=FILE            Analyse only these users
  -p, --profile=NAME|FILE   Analysis profile (default: ad-loyalty)
      --provider=NAME       LLM provider from config
  -m, --model=MODEL         Override the provider's model
  -l, --lang=CODE           Report language: en, ru, uk
      --reason-lang=LANG    Language of the model's explanations ("auto" = as in the messages)
  -f, --format=FMT          html, json, csv (repeatable or comma-separated)
  -o, --out=PATH            Output path without extension
      --chunk=N             Users per request (default 8)
      --max-messages=N      Messages per user sent to the model (default 30, newest)
      --min-messages=N      Skip users with fewer text messages
      --limit=N             Only the N most active users
      --refresh             Ignore the cache
      --dry-run             Show the prompt and the size of the job without calling the model
```

Defaults for all of these live in `config/config.php`.

## Privacy

A chat export contains other people's messages. Please:

- analyse chats you have a legitimate reason to analyse (you run or moderate them, or members agreed);
- keep exports, caches and reports private — `data/` is git-ignored for this reason;
- remember the report is a model's opinion based on a sample of messages. Use it as a hint, not as a verdict about a person.

Messages are sent to the LLM provider you choose. Use a local model (`ollama`) if the data must not leave your machine.

## Development

```bash
composer test
```

Tests cover JSON repair, quote verification, id filters, message collection on the demo export,
profile validation and the classifier's batching / retry / cache logic (with a scripted provider).

```
src/
  Telegram/   streaming reader, message helpers, id normalisation
  Filter/     AllUsersFilter, IdListFilter
  Collect/    per-user message collection
  Profile/    YAML profiles and categories
  Llm/        provider interface, HTTP client with retries, providers, factory
  Analysis/   prompt builder, classifier, cache, JSON repair, quote verifier
  Report/     HTML (PHP template), JSON and CSV renderers
  I18n/       translator for report strings (lang/*.php)
  Console/    Symfony Console commands
```

## License

MIT
