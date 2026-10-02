<?php

declare(strict_types=1);

namespace ChatLens\Console;

use ChatLens\Analysis\AnalysisResult;
use ChatLens\Analysis\Classifier;
use ChatLens\Analysis\PromptBuilder;
use ChatLens\Analysis\ResultCache;
use ChatLens\Collect\CollectionResult;
use ChatLens\Collect\UserMessageCollector;
use ChatLens\Filter\AllUsersFilter;
use ChatLens\Filter\IdListFilter;
use ChatLens\Filter\UserFilterInterface;
use ChatLens\Kernel;
use ChatLens\Llm\ProviderFactory;
use ChatLens\Profile\ProfileLoader;
use ChatLens\Report\RendererFactory;
use ChatLens\Telegram\TelegramExportReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'analyze', description: 'Classify chat participants with an LLM and build a report')]
final class AnalyzeCommand extends Command
{
    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $cfg = $this->kernel->config();

        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Telegram export (result.json)', $cfg->get('paths.input'))
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Analyse only users from this list (JSON or one id per line). Default: everyone')
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile name from profiles/ or a path to a YAML file', $cfg->get('defaults.profile'))
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'LLM provider from config', $cfg->get('defaults.provider'))
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Override the provider\'s model')
            ->addOption('lang', 'l', InputOption::VALUE_REQUIRED, 'Report language (en, ru, uk)', $cfg->get('defaults.lang'))
            ->addOption('reason-lang', null, InputOption::VALUE_REQUIRED, 'Language of the model\'s explanations, e.g. "Russian"; "auto" = language of the messages', $cfg->get('defaults.reason_language'))
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Report formats: html, json, csv', $cfg->get('defaults.formats'))
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Output file name without extension (default: data/output/<chat>_<profile>_<time>)')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Users per request', $cfg->get('analysis.chunk_size'))
            ->addOption('max-messages', null, InputOption::VALUE_REQUIRED, 'Messages per user sent to the model', $cfg->get('collect.max_messages_per_user'))
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Skip users with fewer text messages', $cfg->get('collect.min_messages'))
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Analyse only the N most active users (handy for a cheap test run)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Ignore cached answers and ask the model again')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Collect messages and estimate the work without calling the model')
            ->setHelp(<<<'HELP'
                Examples:

                  <info>./chatlens analyze</info>                               everyone, default profile and provider
                  <info>./chatlens analyze --ids=data/input/ids.json</info>    only listed users
                  <info>./chatlens analyze -p cats --provider=claude -l ru -f html -f csv</info>
                  <info>./chatlens analyze --limit=20 --dry-run</info>          see what would be sent, for free

                Answers are cached per user in data/cache, so an interrupted or repeated run
                only pays for users that were not analysed yet. Use --refresh to start over.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cfg = $this->kernel->config();
        $logger = new ConsoleLogger($output, [
            \Psr\Log\LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
            \Psr\Log\LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL,
        ]);

        // --- what & how ---------------------------------------------------------
        $profile = (new ProfileLoader($this->kernel->configPath('paths.profiles')))->load((string) $input->getOption('profile'));
        $reader = new TelegramExportReader($this->kernel->path((string) $input->getOption('input')));
        $chatName = $reader->chatName();
        $filter = $this->filter($input);
        $lang = (string) $input->getOption('lang');
        $formats = $this->formats($input);
        $renderers = new RendererFactory($this->kernel->configPath('paths.template'), $this->kernel->configPath('paths.lang'));
        foreach ($formats as $format) {
            $renderers->create($format, $lang); // fail fast on a bad format or language
        }

        $io->title('ChatLens · ' . $profile->title($lang));
        $io->definitionList(
            ['Chat' => $chatName],
            ['Profile' => $profile->id],
            ['Users' => $filter->describe()],
        );

        // --- collect ------------------------------------------------------------
        $collector = new UserMessageCollector(
            maxMessagesPerUser: (int) $input->getOption('max-messages'),
            maxMessageLength: (int) $cfg->get('collect.max_message_length', 800),
            minMessages: (int) $input->getOption('min-messages'),
            take: (string) $cfg->get('collect.take', UserMessageCollector::TAKE_LAST),
        );

        $collected = $collector->collect(
            $reader->messages(),
            $filter,
            static fn (int $scanned, int $users) => $io->writeln(sprintf('  scanned %s messages, %d users…', number_format($scanned), $users))
        );

        $users = $collected->users;
        if ($input->getOption('limit') !== null) {
            usort($users, static fn ($a, $b) => $b->totalMessages <=> $a->totalMessages);
            $users = array_slice($users, 0, max(1, (int) $input->getOption('limit')));
        }

        $io->writeln(sprintf(
            'Scanned <info>%s</info> messages: <info>%d</info> users with text%s, <info>%d</info> selected.',
            number_format($collected->scannedMessages),
            count($collected->users) + $collected->skippedUsers,
            $collected->skippedUsers ? sprintf(' (%d below --min-messages)', $collected->skippedUsers) : '',
            count($users),
        ));

        if ($users === []) {
            $io->warning('Nobody to analyse. Check --ids and --min-messages.');

            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $this->dryRun($io, $users, $profile, $chatName, (string) $input->getOption('reason-lang'), (int) $input->getOption('chunk'));

            return Command::SUCCESS;
        }

        // --- classify -----------------------------------------------------------
        $provider = (new ProviderFactory($cfg, $logger))->create(
            (string) $input->getOption('provider'),
            $input->getOption('model') !== null ? (string) $input->getOption('model') : null,
        );

        $classifier = new Classifier(
            provider: $provider,
            cache: new ResultCache($this->kernel->configPath('paths.cache'), readEnabled: !$input->getOption('refresh')),
            chunkSize: (int) $input->getOption('chunk'),
            retryChunkSize: (int) $cfg->get('analysis.retry_chunk_size', 3),
            maxTokens: (int) $cfg->get('analysis.max_tokens', 8192),
            delayMs: (int) $cfg->get('analysis.delay_ms', 1000),
            quotePolicy: (string) $cfg->get('analysis.quotes', Classifier::QUOTES_MARK),
            logger: $logger,
        );

        $io->section(sprintf('Analysing with %s / %s', $provider->name(), $provider->model()));
        $run = $classifier->classify($users, $profile, $chatName, (string) $input->getOption('reason-lang'));

        $result = new AnalysisResult(
            chatName: $chatName,
            profile: $profile,
            provider: $provider->name(),
            model: $provider->model(),
            generatedAt: new \DateTimeImmutable(),
            classifications: $run->classifications,
            failed: $run->failed,
            stats: $run->stats,
            meta: $this->meta($collected, $filter, $input),
        );

        // --- report -------------------------------------------------------------
        $base = $this->outputBase($input, $chatName, $profile->id);
        $written = [];
        foreach ($formats as $format) {
            $file = "{$base}.{$format}";
            file_put_contents($file, $renderers->create($format, $lang)->render($result));
            $written[] = $file;
        }

        $this->summary($io, $result, $written);

        return $run->failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function filter(InputInterface $input): UserFilterInterface
    {
        $ids = $input->getOption('ids');

        return $ids === null || $ids === ''
            ? new AllUsersFilter()
            : IdListFilter::fromFile($this->kernel->path((string) $ids));
    }

    /** @return list<string> */
    private function formats(InputInterface $input): array
    {
        $formats = [];
        foreach ((array) $input->getOption('format') as $value) {
            foreach (explode(',', (string) $value) as $f) {
                $f = strtolower(trim($f));
                if ($f !== '') {
                    $formats[$f] = $f;
                }
            }
        }

        return array_values($formats) ?: ['html'];
    }

    private function outputBase(InputInterface $input, string $chatName, string $profileId): string
    {
        $out = $input->getOption('out');
        if ($out !== null && $out !== '') {
            $base = (string) preg_replace('/\.(html|json|csv)$/i', '', $this->kernel->path((string) $out));
        } else {
            $slug = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($chatName)), '-') ?: 'chat';
            $base = sprintf('%s/%s_%s_%s', $this->kernel->configPath('paths.output'), mb_substr($slug, 0, 40), $profileId, date('Ymd-His'));
        }

        $dir = dirname($base);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create output directory {$dir}");
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function meta(CollectionResult $collected, UserFilterInterface $filter, InputInterface $input): array
    {
        return [
            'scanned_messages' => $collected->scannedMessages,
            'matched_messages' => $collected->matchedMessages,
            'scope' => $filter->describe() . ($input->getOption('limit') !== null ? ', top ' . (int) $input->getOption('limit') . ' by activity' : ''),
            'max_messages_per_user' => (int) $input->getOption('max-messages'),
        ];
    }

    /** @param list<\ChatLens\Collect\ChatUser> $users */
    private function dryRun(SymfonyStyle $io, array $users, \ChatLens\Profile\Profile $profile, string $chatName, string $reasonLang, int $chunk): void
    {
        $prompts = new PromptBuilder();
        $system = $prompts->system($profile, $chatName, $reasonLang);

        $chars = 0;
        $messages = 0;
        foreach (array_chunk($users, max(1, $chunk)) as $batch) {
            $chars += mb_strlen($system) + mb_strlen($prompts->userPayload($batch)['payload']);
        }
        foreach ($users as $u) {
            $messages += count($u->messages);
        }

        $requests = (int) ceil(count($users) / max(1, $chunk));
        $io->section('Dry run — nothing was sent');
        $io->definitionList(
            ['Users' => count($users)],
            ['Messages to send' => $messages],
            ['Requests' => $requests],
            ['Input size' => sprintf('%s characters, roughly %s tokens', number_format($chars), number_format((int) ($chars / 3.5)))],
        );
        $io->writeln('<comment>System prompt:</comment>');
        $io->writeln($system);
        $io->newLine();
        $io->note('Token estimate is rough (Cyrillic text costs more tokens per character than English). Cached users will not be sent again.');
    }

    /** @param list<string> $files */
    private function summary(SymfonyStyle $io, AnalysisResult $result, array $files): void
    {
        $rows = [];
        foreach ($result->counts() as $key => $n) {
            $rows[] = [$result->profile->category($key)->label('en'), $n];
        }
        if ($result->failed) {
            $rows[] = ['<error>not analysed</error>', count($result->failed)];
        }

        $io->section('Result');
        $io->table(['Category', 'Users'], $rows);

        $s = $result->stats;
        $io->writeln(sprintf(
            'Requests: %d · from cache: %d · tokens in/out: %s/%s · batch splits: %d · retried: %d',
            $s->requests,
            $s->cacheHits,
            number_format($s->inputTokens),
            number_format($s->outputTokens),
            $s->splits,
            $s->retried,
        ));
        if ($s->unverifiedQuotes > 0) {
            $io->writeln(sprintf('<comment>%d quotes were not found in the messages and are marked in the report.</comment>', $s->unverifiedQuotes));
        }
        if ($s->correctedCategories > 0) {
            $io->writeln(sprintf('<comment>%d answers had an unknown category and were moved to "%s".</comment>', $s->correctedCategories, $result->profile->fallback));
        }

        $io->newLine();
        $io->listing($files);

        if ($result->failed) {
            $io->warning(sprintf('%d users got no verdict. Run the same command again to retry only them.', count($result->failed)));
        } else {
            $io->success('Done');
        }
    }
}
