<?php declare(strict_types=1);


namespace app\Services;

use app\Exceptions\InvalidConfigurationException;
use Cz\Git\GitRepository;
use Github\Client;

/**
 * Class Github
 * @package app\Services
 */
final class Github
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
     * Github constructor.
     * @param array $config
     * @param array $auth
     */
    public function __construct(array $config, array $auth = [])
    {
        $this->config = $config;

        // @todo inject Client and Logger
        $this->client = new Client();

        if (isset($auth['github-oauth']['github.com'])) {
            $this->client->authenticate($config['github-oauth']['github.com'], Client::AUTH_HTTP_TOKEN);
        }
    }

    /**
     * @param array $skip
     * @return array
     */
    public function repositories(array $skip = []): array
    {
        $repositories = [];
        if (isset($this->config['github']['organisation'])) {
            $organisation = $this->config['github']['organisation'];
            $repositories = array_merge($repositories, $this->client->repository()->org($organisation));
        }
        if (isset($this->config['github']['username'])) {
            $user = $this->config['github']['username'];
            $repositories = array_merge($repositories, $this->client->user()->repositories($user));
        }
        // Filter skipped repository names
        $repositories = array_filter($repositories, function ($repo) use ($skip) {
            return !in_array($repo['name'], $skip, true);
        });

        usort($repositories, static function ($repoA, $repoB) {
            return strtolower($repoA['name']) <=> strtolower($repoB['name']);
        });

        if (!isset($repositories)) {
            throw new InvalidConfigurationException('Missing either github.organisation or github.username key');
        }

        // Filter only for PHP repositories
        $repositories = array_diff(array_filter($repositories, function ($repo) {
            return $repo['language'] === 'PHP';
        }), $skip);

        return $repositories;
    }

    /**
     * @param array $repository
     * @return $this
     * @throws \Cz\Git\GitException
     */
    public function checkout(array $repository): self
    {
//        $repoPath = $this->outputDir . '/' . $repository['name'];
        $repoPath = $repository['name'];
        if (!is_dir($repoPath)) {
            // @todo log messages
            // sprintf('Cloning repository %s...', $repository['name'])
            // Clone new repository
            GitRepository::cloneRepository($repository['ssh_url'])->checkout('master');
        } else {
            // Fetch updates
            $local = new GitRepository($repoPath);
            // @todo log message
            // sprintf('Updating repository %s...', $repository['name'])
            $local->checkout('master')->pull();
        }
        return $this;
    }
}
