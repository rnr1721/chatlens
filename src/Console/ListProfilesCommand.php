<?php

declare(strict_types=1);

namespace ChatLens\Console;

use ChatLens\Kernel;
use ChatLens\Llm\ProviderFactory;
use ChatLens\Profile\ProfileLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'profiles', description: 'List analysis profiles and configured LLM providers')]
final class ListProfilesCommand extends Command
{
    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cfg = $this->kernel->config();
        $loader = new ProfileLoader($this->kernel->configPath('paths.profiles'));
        $default = $cfg->get('defaults.profile');

        $rows = [];
        foreach ($loader->available() as $id => $file) {
            try {
                $p = $loader->load($file);
                $rows[] = [
                    $id === $default ? "<info>{$id}</info> (default)" : $id,
                    $p->description ?: $p->title('en'),
                    implode(', ', $p->categoryKeys()),
                ];
            } catch (\Throwable $e) {
                $rows[] = [$id, '<error>' . $e->getMessage() . '</error>', ''];
            }
        }

        $io->section('Profiles (' . $cfg->get('paths.profiles') . '/)');
        $io->table(['Profile', 'Description', 'Categories'], $rows);
        $io->writeln('Create your own: copy <info>profiles/_template.yaml</info>, edit the prompt and categories.');

        $providerRows = [];
        $factory = new ProviderFactory($cfg);
        foreach ($factory->names() as $name) {
            try {
                $provider = $factory->create($name);
                $status = '<info>ready</info>';
                $model = $provider->model();
            } catch (\InvalidArgumentException $e) {
                $status = '<comment>' . $e->getMessage() . '</comment>';
                $model = (string) $cfg->get("providers.{$name}.model", '');
            }
            $providerRows[] = [
                $name === $cfg->get('defaults.provider') ? "{$name} (default)" : $name,
                $cfg->get("providers.{$name}.driver"),
                $model,
                $status,
            ];
        }

        $io->section('Providers (config/config.php)');
        $io->table(['Provider', 'Driver', 'Model', 'Status'], $providerRows);

        return Command::SUCCESS;
    }
}
