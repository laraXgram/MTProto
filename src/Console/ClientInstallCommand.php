<?php

namespace LaraGram\MTProto\Console;

use LaraGram\Console\Command;
use LaraGram\Filesystem\Filesystem;
use LaraGram\Console\Attribute\AsCommand;

#[AsCommand(name: 'install:client')]
class ClientInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'install:client
                    {--force : Overwrite any existing Client listens file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an Client listens file';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if (file_exists($clientListensPath = $this->laragram->basePath('listens/client.php')) &&
            ! $this->option('force')) {
            $this->components->error('Client listens file already exists.');
        } else {
            $this->components->info('Published Client listens file.');

            copy(__DIR__.'/stubs/client-listens.stub', $clientListensPath);

            $this->uncommentClientListensFile();
        }
    }

    /**
     * Uncomment the Client listens file in the application bootstrap file.
     *
     * @return void
     */
    protected function uncommentClientListensFile()
    {
        $appBootstrapPath = $this->laragram->bootstrapPath('app.php');

        $content = file_get_contents($appBootstrapPath);
        $definition = 'client: __DIR__.\'/../listens/client.php\',';

        if (preg_match('/^\s*client\s*:/m', $content)) {
            return;
        }

        if (preg_match('#//\s*client\s*:.*$#m', $content)) {
            (new Filesystem)->put(
                $appBootstrapPath,
                preg_replace('#//\s*client\s*:.*$#m', $definition, $content, 1),
            );
        } elseif (preg_match('#^(\s*)bot\s*:\s*__DIR__\s*\.\s*\'/\.\./listens/bot\.php\',#m', $content, $matches)) {
            (new Filesystem)->replaceInFile(
                $matches[0],
                $matches[0].PHP_EOL.ltrim($matches[1], "\r\n").$definition,
                $appBootstrapPath,
            );
        } else {
            $this->components->warn("Unable to automatically add Client listen definition to [{$appBootstrapPath}]. Client listen file should be registered manually.");

            return;
        }
    }
}
