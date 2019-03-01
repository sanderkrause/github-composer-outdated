<?php declare(strict_types=1);


namespace app\Services;


use Symfony\Component\Process\Process;

/**
 * Class Composer
 * @package app\Services
 */
final class Composer
{
    /**
     * @var string
     */
    private $composerPath;
    
    /**
     * Composer constructor.
     * @param string $composerPath
     */
    public function __construct(string $composerPath)
    {
        $this->composerPath = $composerPath;
    }
    
    /**
     * @return Process
     */
    public function install(): Process
    {
        $process = new Process([$this->composerPath, '-q', 'install']);
        // Disable timeout
        $process->setTimeout(null)->run();
        return $process;
    }
    
    /**
     * @param bool $minorOnly
     * @return Process
     */
    public function outdated($minorOnly = false): Process
    {
        $command = [$this->composerPath, 'outdated', '-f', 'json', '--direct'];
        if ($minorOnly) {
            $command[] = '-m';
        }
        $process = new Process($command);
        // Disable timeout
        $process->setTimeout(null)->run();
        return $process;
    }
}
