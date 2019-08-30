<?php /** @noinspection DisconnectedForeachInstructionInspection */


namespace app\Commands;


use app\Services\Composer;
use app\Services\Github;
use Cz\Git\GitException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class OutdatedCommand
 * @package app\Commands
 */
final class OutdatedCommand extends Command
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var array
     */
    private $auth;

    /**
     * @var string
     */
    private $outputDir;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var Github
     */
    private $github;

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('outdated')
            ->addOption('minor-only', 'm', InputOption::VALUE_NONE, 'Checks only for minor version upgrades.')
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Fail at first error (default false).')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of repositories to skip.', [])
            ->addOption('dry', null, InputOption::VALUE_NONE,
                'Only show which repositories shall be checked. Do not actually do anything.')
            ->setDescription('Checks configured Github repositories for outdated Composer dependencies.');
    }

    /**
     * @inheritDoc
     * @throws GitException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skippedRepositories = array_filter(array_map('trim', explode(',', $input->getOption('skip'))));
        $currentWorkingDir = getcwd();
        foreach ($this->github->repositories($skippedRepositories) as $repository) {

            if ($input->getOption('dry')) {
                $output->writeln('Dry run, checking ' . $repository['name']);
            }

            chdir($this->outputDir);
            $this->github->checkout($repository);
            // Change working directory to current repository
            chdir($this->outputDir . '/' . $repository['name']);

            $process = $this->composer->install();
            if ($input->getOption('fail-fast') && $process->isSuccessful() === false) {
                // @todo log error output
                break;
            }

            // Running composer outdated
            $process = $this->composer->outdated($input->getOption('minor-only'));
            if ($input->getOption('fail-fast') && $process->isSuccessful() === false) {
                // @todo log error output
                break;
            }

            // Parsing output
            $processOutput = $process->getOutput();
            $json = json_decode($processOutput, true);
            // Attempt to fix output, find first { and remove leading characters before
            if (json_last_error() !== JSON_ERROR_NONE) {
                $processOutput = preg_replace('/^[^{]+{/', '{', $processOutput);
                $json = json_decode($processOutput, true);
            }
            if (!empty($json)) {
                $output->writeln(json_encode($json, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT));
            }
        }
        // Reset working directory back to what it was
        chdir($currentWorkingDir);
    }

    /**
     * @inheritDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = $this->readConfiguration();
        $this->outputDir = $this->prepareOutputDirectory();

        // @todo service injection? also include logger
        $this->auth = $this->stealComposerAuth();
        $this->github = new Github($this->config);

        $this->composer = new Composer($this->config['composer']['path']);
    }

    /**
     * Reads configuration from repositories.yml
     * @return array
     */
    private function readConfiguration(): array
    {
        return Yaml::parseFile(PROJECT_ROOT . '/repositories.yml');
    }

    /**
     * Attempts to read auth.json from the .composer directory
     * @return array
     */
    private function stealComposerAuth(): array
    {
        $composerAuthPath = realpath(getenv('HOME') . '/.composer/auth.json');
        if (is_readable($composerAuthPath)) {
            $auth = json_decode(file_get_contents($composerAuthPath), true);
        }

        return $auth ?? [];
    }

    /**
     * @throws \RuntimeException
     * @return string Absolute path of the output directory
     */
    private function prepareOutputDirectory(): string
    {
        $outputDirectory = PROJECT_ROOT . '/_output';
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputDirectory));
        }

        return realpath($outputDirectory);
    }
}
