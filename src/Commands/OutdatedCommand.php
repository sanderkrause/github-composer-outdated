<?php


namespace app\Commands;


use app\Exceptions\InvalidConfigurationException;
use Cz\Git\GitException;
use Cz\Git\GitRepository;
use Github\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;
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
    
    /**
     * @var int
     */
    private $terminalWidth;
    
    /**
     * @var OutputInterface
     */
    private $outputStream;
    
    /**
     * @var ProgressBar
     */
    private $primaryProgressBar;
    
    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('outdated')
            ->addOption('direct', 'd', InputOption::VALUE_NONE, 'Check only dependencies listed in each composer.json.')
            ->addOption('minor-only', 'm', InputOption::VALUE_NONE, 'Checks the configuration for problems.')
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Fail at first error (default false).')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of repositories to skip.',
                [])// @todo implement
            ->setDescription('Checks configured Github repositories for outdated Composer dependencies.');
    }
    
    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (isset($this->config['github']['organisation'])) {
            $organisation = $this->config['github']['organisation'];
            $repositories = $this->client->repository()->org($organisation);
        }
        
        if (!isset($repositories)) {
            throw new InvalidConfigurationException('Missing either github.organisation or github.username key');
        }
        
        // Filter only for PHP repositories
        $repositories = array_filter($repositories, function ($repo) {
            return $repo['language'] === 'PHP';
        });
        
        $repositorySection = $output->section('Repositories');
        $composerSection = $output->section('Composer');
        
        // Force verbosity for consistent progress bar
        $this->primaryProgressBar = new ProgressBar($repositorySection, \count($repositories));
        $this->primaryProgressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% -- %message%');
        $this->primaryProgressBar->start();
        $secondaryProgressBar = new ProgressBar($composerSection, 3);
        $secondaryProgressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $secondaryProgressBar->setMessage('Working...');
        
        $failed = false;
        
        $currentWorkingDir = getcwd();
        foreach ($repositories as $repository) {
            $this->primaryProgressBar->setMessage($repository['name']);
            $this->primaryProgressBar->advance();
            
            $secondaryProgressBar->clear();
            $secondaryProgressBar->start();
            
            chdir($this->outputDir);
            $this->checkoutOrPull($repository);
            // Change working directory to current repository
            chdir($this->outputDir . '/' . $repository['name']);
            
            $secondaryProgressBar->setMessage('Running composer install');
            $secondaryProgressBar->advance();
            
            $process = new Process([$this->config['composer']['path'], '-q', 'install']);
            // Disable timeout
            $process->setTimeout(null);
            
            try {
                $process->mustRun();
            } catch (ProcessFailedException $e) {
                $secondaryProgressBar->setMessage(trim($process->getErrorOutput()));
                if ($input->getOption('fail-fast')) {
                    $failed = true;
                    break;
                }
            }
            
            $secondaryProgressBar->setMessage('Running composer outdated');
            $secondaryProgressBar->advance();
            // @todo add -d and -m options from $input
            $process = new Process([$this->config['composer']['path'], 'outdated', '-f', 'json']);
            // Disable timeout
            $process->setTimeout(null);
            try {
                $process->mustRun();
            } catch (ProcessFailedException $e) {
                $secondaryProgressBar->setMessage(trim($process->getErrorOutput()));
                if ($input->getOption('fail-fast')) {
                    $failed = true;
                    break;
                }
            }
            
            $secondaryProgressBar->setMessage('Parsing output');
            $secondaryProgressBar->advance();
            
            $processOutput = $process->getOutput();
            try {
                $json = json_decode($processOutput, true);
                // Attempt to fix output, find first { and remove leading characters before
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $processOutput = preg_replace('/^[^{]+{/', '{', $processOutput);
                    $json = json_decode($processOutput, true);
                }
                if ($json !== null) {
                    file_put_contents($currentWorkingDir . '/' . $repository['name'] . '.json',
                        json_encode($json, JSON_PRETTY_PRINT));
                } else {
                    file_put_contents($currentWorkingDir . '/' . $repository['name'] . '.json', $processOutput);
                    throw new RuntimeException('invalid json');
                }
            } catch (\RuntimeException $e) {
                $secondaryProgressBar->setMessage($e->getMessage());
                $secondaryProgressBar->display();
                if ($input->getOption('fail-fast')) {
                    $failed = true;
                    break;
                }
            }
        }
        if ($failed && !$input->getOption('fail-fast')) {
            $this->primaryProgressBar->finish();
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
        
        $this->terminalWidth = (int)exec('tput cols');
        
        $this->outputStream = $output;
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
    
    /**
     *
     * @param array $repository
     */
    private function checkoutOrPull(array $repository)
    {
        $repoPath = $this->outputDir . '/' . $repository['name'];
        if (!is_dir($repoPath)) {
            $this->primaryProgressBar->setMessage(sprintf('Cloning repository %s...', $repository['name']));
            // Clone new repository
            try {
                GitRepository::cloneRepository($repository['ssh_url'])->checkout('master');
            } catch (GitException $e) {
                $this->outputStream->writeln($e->getMessage());
            }
        } else {
            // Fetch updates
            try {
                $local = new GitRepository($repoPath);
                $this->primaryProgressBar->setMessage(sprintf('Updating repository %s...', $repository['name']));
                $local->checkout('master')->pull();
            } catch (GitException $e) {
                $this->outputStream->writeln($e->getMessage());
            }
        }
    }
}
