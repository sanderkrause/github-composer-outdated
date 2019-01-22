<?php


namespace app\Commands;


use Cz\Git\GitException;
use Cz\Git\GitRepository;
use Github\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class OutdatedCommand
 * @package app\Commands
 */
class OutdatedCommand extends Command
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var array
     */
    private $auth;

    /**
     * @var string
     */
    private $outputDir;

    private $requiredKeys = [
        'composer' => ['path'],
        'github' => ['organisation', 'username'] // @todo either / or
    ];

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('outdated')
            ->addOption('dry', null, InputOption::VALUE_NONE, 'Executes command without actually doing anything.')
            ->addOption('lint', 'l', InputOption::VALUE_NONE, 'Checks the configuration for problems.')
            ->setDescription('Checks configured Github repositories for outdated Composer dependencies.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organisation = $this->config['github']['organisation'];

        $repositories = $this->client->repository()->org($organisation);
        // Filter only for PHP repositories
        $repositories = array_filter($repositories, function ($repo) {
            return $repo['language'] === 'PHP';
        });

        $currentWorkingDir = getcwd();
        chdir($this->outputDir);
        foreach ($repositories as $repository) {
            $output->writeln(sprintf('Cloning repository %s...', $repository['name']));

            if (!$input->getOption('dry')) {
                try {
                        GitRepository::cloneRepository($repository['ssh_url'])->checkout('master');
                } catch (GitException $e) {
                    $output->writeln($e->getMessage());
                }
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

        $this->auth = $this->stealComposerAuth();
        $this->client = new Client();
        if (isset($this->auth['github-oauth']['github.com'])) {
            $this->client->authenticate($this->auth['github-oauth']['github.com'], Client::AUTH_HTTP_TOKEN);
        }
        $this->outputDir = $this->prepareOutputDirectory();
    }

    /**
     *
     * @throws \RuntimeException
     * @return array
     */
    private function readConfiguration(): array
    {
        $config = Yaml::parseFile(PROJECT_ROOT . '/repositories.yml');

        // @todo compare arrays by keys, multi-dimensional

        if (array_intersect_key($this->requiredKeys, array_keys($config))) {
            return $config;
        }

        throw new \RuntimeException('Invalid configuration');
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
