<?php

declare(strict_types=1);

namespace ChatLens\Console;

use ChatLens\Filter\AllUsersFilter;
use ChatLens\Kernel;
use ChatLens\Telegram\TelegramExportReader;
use ChatLens\Telegram\TelegramMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exports all chat participants to an editable list — delete the lines you don't need
 * and pass the file to `analyze --ids=…`.
 */
#[AsCommand(name: 'ids:export', description: 'Export chat participants (id, name, message count) to an editable JSON list')]
final class ExportIdsCommand extends Command
{
    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Telegram export (result.json)', $this->kernel->config()->get('paths.input'))
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Where to write the list', 'data/input/ids.json')
            ->addOption('min-messages', null, InputOption::VALUE_REQUIRED, 'Skip users with fewer text messages', 1)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the output file if it exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $out = $this->kernel->path((string) $input->getOption('out'));

        if (is_file($out) && !$input->getOption('force')) {
            $io->error("{$out} already exists. Use --force to overwrite.");

            return Command::FAILURE;
        }

        $reader = new TelegramExportReader($this->kernel->path((string) $input->getOption('input')));
        $filter = new AllUsersFilter();
        $min = max(1, (int) $input->getOption('min-messages'));

        /** @var array<string, array{id: string, name: string, messages: int}> $users */
        $users = [];
        $scanned = 0;
        foreach ($reader->messages() as $message) {
            $scanned++;
            if (!TelegramMessage::isAuthored($message) || TelegramMessage::text($message) === '') {
                continue;
            }
            $id = TelegramMessage::authorId($message);
            if (!$filter->accepts($id)) {
                continue;
            }
            $users[$id] ??= ['id' => $id, 'name' => '', 'messages' => 0];
            $users[$id]['name'] = TelegramMessage::authorName($message);
            $users[$id]['messages']++;
        }

        $list = array_values(array_filter($users, static fn (array $u) => $u['messages'] >= $min));
        usort($list, static fn (array $a, array $b) => $b['messages'] <=> $a['messages'] ?: strcmp($a['id'], $b['id']));

        if (!is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        file_put_contents($out, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        $io->success(sprintf(
            '%d participants from «%s» (%s messages scanned) → %s',
            count($list),
            $reader->chatName(),
            number_format($scanned),
            $out
        ));
        $io->writeln('Remove the users you don\'t need, then run: <info>./chatlens analyze --ids=' . $input->getOption('out') . '</info>');

        return Command::SUCCESS;
    }
}
