<?php declare(strict_types=1);

namespace Model {

    final class Tool
    {
        private $name;
        private $summary;
        private $website;
        private $command;

        public function __construct(string $name, string $summary, string $website, Command $command)
        {
            $this->name = $name;
            $this->summary = $summary;
            $this->website = $website;
            $this->command = $command;
        }

        public static function import(array $tool): self
        {
            \Assert\requireFields(['name', 'summary', 'website', 'command'], $tool, 'tool');

            return new self($tool['name'], $tool['summary'], $tool['website'], self::importCommand($tool));
        }

        private static function importCommand(array $tool): Command
        {
            $commands = [];

            try {
                foreach ($tool['command'] as $type => $command) {
                    $commands = array_merge($commands, iterator_to_array(self::createCommands($type, $command)));
                }
            } catch (\Exception $e) {
                throw new \RuntimeException(sprintf('Wrongly defined command for the tool: %s', json_encode($tool)), 0, $e);
            }

            if (empty($commands)) {
                throw new \RuntimeException(sprintf('No valid command defined for the tool: %s', json_encode($tool)));
            }

            return 1 === count($commands) ? $commands[0] : new MultiStepCommand($commands);
        }

        private static function createCommands($type, $command): \Iterator
        {
            $factories = [
                'phar-download' => 'Model\PharDownloadCommand::import',
                'file-download' => 'Model\FileDownloadCommand::import',
                'box-build' => 'Model\BoxBuildCommand::import',
                'composer-install' => 'Model\ComposerInstallCommand::import',
                'composer-global-install' => 'Model\ComposerGlobalInstallCommand::import',

            ];

            if (!isset($factories[$type])) {
                throw new \RuntimeException(sprintf('Unrecognised command: "%s". Supported commands are: "%s".', $type, implode(', ', array_keys($factories))));
            }

            $command = !is_numeric(key($command)) ? array($command) : $command;

            foreach ($command as $c) {
                yield $factories[$type]($c);
            }
        }

        public function name(): string
        {
            return $this->name;
        }

        public function summary(): string
        {
            return $this->summary;
        }

        public function website(): string
        {
            return $this->website;
        }

        public function command(): Command
        {
            return $this->command;
        }
    }

    interface Command
    {
        public function __toString(): string;
    }

    final class PharDownloadCommand implements Command
    {
        private $phar;
        private $bin;

        private function __construct(string $phar, string $bin)
        {
            $this->phar = $phar;
            $this->bin = $bin;
        }

        public static function import(array $command): Command
        {
            \Assert\requireFields(['phar', 'bin'], $command, 'PharDownloadCommand');

            return new self($command['phar'], $command['bin']);
        }

        public function __toString(): string
        {
            return sprintf('curl -Ls %s > %s && chmod +x %s', $this->phar, $this->bin, $this->bin);
        }
    }

    final class FileDownloadCommand implements Command
    {
        private $url;
        private $file;

        private function __construct(string $url, string $file)
        {
            $this->url = $url;
            $this->file = $file;
        }

        public static function import(array $command): Command
        {
            \Assert\requireFields(['url', 'file'], $command, 'FileDownloadCommand');

            return new self($command['url'], $command['file']);
        }

        public function __toString(): string
        {
            return sprintf('curl -Ls %s > %s', $this->url, $this->file);
        }
    }

    final class BoxBuildCommand implements Command
    {
        private $repository;
        private $phar;
        private $bin;

        private function __construct(string $repository, string $phar, string $bin)
        {
            $this->repository = $repository;
            $this->phar = $phar;
            $this->bin = $bin;
        }

        public static function import(array $command): Command
        {
            \Assert\requireFields(['repository', 'phar', 'bin'], $command, 'BoxBuildCommand');

            return new self($command['repository'], $command['phar'], $command['bin']);
        }

        public function __toString(): string
        {
            return sprintf(
                'cd $HOME && git clone %s && cd $HOME/%s && composer install --no-dev --no-suggest --prefer-dist -n && box build && mv %s %s && chmod +x %s && cd && rm -rf $HOME/%s',
                $this->repository,
                $this->getTargetDir(),
                $this->phar,
                $this->bin,
                $this->bin,
                $this->getTargetDir()
            );
        }

        private function getTargetDir(): string
        {
            return preg_replace('#^.*/(.*?)(.git)?$#', '$1', $this->repository) ?? 'tmp';
        }
    }

    final class ComposerInstallCommand implements Command
    {
        private $repository;

        public function __construct(string $repository)
        {
            $this->repository = $repository;
        }

        public static function import(array $command): Command
        {
            \Assert\requireFields(['repository'], $command, 'ComposerInstallCommand');

            return new self($command['repository']);
        }

        public function __toString(): string
        {
            return sprintf(
                'cd $HOME && git clone %s && cd $HOME/%s && composer install --no-dev --no-suggest --prefer-dist -n',
                $this->repository,
                $this->getTargetDir()
            );
        }

        private function getTargetDir(): string
        {
            return preg_replace('#^.*/(.*?)(.git)?$#', '$1', $this->repository) ?? 'tmp';
        }
    }

    final class ComposerGlobalInstallCommand implements Command
    {
        private $package;

        public function __construct(string $package)
        {
            $this->package = $package;
        }

        public static function import(array $command): Command
        {
            \Assert\requireFields(['package'], $command, 'ComposerGlobalInstallCommand');

            return new self($command['package']);
        }

        public function __toString(): string
        {
            return sprintf('composer global require --no-suggest --prefer-dist --update-no-dev -n %s', $this->package);
        }

        public function getPackage(): string
        {
            return $this->package;
        }
    }

    final class MultiStepCommand implements Command
    {
        private $commands;
        private $glue;

        public function __construct(array $commands, $glue = ' && ')
        {
            $this->commands = array_map(function (Command $command) {
                return $command;
            }, $commands);
            $this->glue = $glue;
        }

        public function __toString(): string
        {
            return implode($this->glue, array_map(function (Command $command) {
                return (string)$command;
            }, $this->commands));
        }
    }

    final class ComposerGlobalMultiInstallCommand implements Command
    {
        private $commands;

        public function __construct(array $commands)
        {
            $this->commands = array_map(function (ComposerGlobalInstallCommand $command) {
                return $command;
            }, $commands);
        }

        public function __toString(): string
        {
            $packages = implode(' ', array_map(function (ComposerGlobalInstallCommand $command) {
                return $command->getPackage();
            }, $this->commands));

            return sprintf('composer global require --no-suggest --prefer-dist --update-no-dev -n %s', $packages);
        }
    }

    final class ShCommand implements Command
    {
        private $command;

        public function __construct(string $command)
        {
            $this->command = $command;
        }

        public function __toString(): string
        {
            return $this->command;
        }
    }
}

namespace Assert {

    function requireFields(array $fields, array $data, string $type)
    {
        $missingFields = array_filter($fields, function (string $field) use ($data) {
            return !isset($data[$field]);
        });

        if (!empty($missingFields)) {
            throw new \InvalidArgumentException(sprintf('Missing fields "%s" in the %s: %s', implode($missingFields, ', '), $type, json_encode($data)));
        }
    }
}

namespace F {

    abstract class PleaseTry
    {
        private $success;
        private $failure;

        protected function __construct($success, Throwable $failure = null)
        {
            $this->success = $success;
            $this->failure = $failure;
        }

        public function get()
        {
            if (null !== $this->failure) {
                throw $this->failure;
            }

            return $this->success;
        }

        public function map(callable $f): PleaseTry
        {
            if (null !== $this->failure) {
                return new Failure($this->failure);
            }

            if ($this->success instanceof PleaseTry) {
                return $this->success->map($f);
            }

            if (is_array($this->success)) {
                return PleaseTry(function () use ($f) {
                    return array_map($f, $this->success);
                });
            }

            return PleaseTry(function () use ($f) {
                return $f($this->success);
            });
        }

        public function filter(callable $f): PleaseTry
        {
            if (null !== $this->failure) {
                return new Failure($this->failure);
            }

            if ($this->success instanceof PleaseTry) {
                return $this->success->filter($f);
            }

            if (is_array($this->success)) {
                return PleaseTry(function () use ($f) {
                    return array_filter($this->success, $f);
                });
            }

            return PleaseTry(function () use ($f) {
                return $f($this->success) ? $this->success : null;
            });
        }

        public function merge(PleaseTry $other): PleaseTry
        {
            if (null !== $this->failure) {
                return new Failure($this->failure);
            }

            if (null !== $other->failure) {
                return new Failure($other->failure);
            }

            $left = is_array($this->success) ? $this->success : array($this->success);
            $right = is_array($other->success) ? $other->success : array($other->success);

            return PleaseTry(function () use ($left, $right) {
                return array_merge($left, $right);
            });
        }
    }

    final class Success extends PleaseTry
    {
        public function __construct($result)
        {
            parent::__construct($result, null);
        }
    }

    final class Failure extends PleaseTry
    {
        public function __construct($result)
        {
            parent::__construct(null, $result);
        }
    }

    function PleaseTry(callable $f): PleaseTry
    {
        try {
            return new Success($f());
        } catch (Throwable $e) {
            return new Failure($e);
        }
    }
}

namespace JsonLoader {

    use F\PleaseTry;
    use F\Success;
    use Model\ShCommand;
    use Model\Tool;

    function LoadFile(string $source): string
    {
        if (!is_readable($source)) {
            throw new \RuntimeException(sprintf('Could read the file: "%s".', $source));
        }

        return file_get_contents($source);
    }

    function ParseJson(string $source): array
    {
        $json = json_decode($source, true);
        if (!$json) {
            throw new \RuntimeException(sprintf('Failed to parse json: %s', $source));
        }
        if (!isset($json['tools'])) {
            throw new \RuntimeException(sprintf('Did not find any tools in: %s', $source));
        }

        return $json['tools'];
    }

    function LoadTools(string $source): PleaseTry
    {
        $defaultTools = [
            new Tool('composer', 'Dependency Manager for PHP', 'https://getcomposer.org/',
                new ShCommand('curl -Ls https://getcomposer.org/composer.phar > /usr/local/bin/composer && chmod +x /usr/local/bin/composer')),
            new Tool('box', 'An application for building and managing Phars', 'https://box-project.github.io/box2/',
                new ShCommand('curl -Ls https://box-project.github.io/box2/installer.php | php && mv box.phar /usr/local/bin/box && chmod +x /usr/local/bin/box')),
        ];

        return
            (new Success($defaultTools))
                ->merge(
                    (new Success($source))
                        ->map('JsonLoader\LoadFile')
                        ->map('JsonLoader\ParseJson')
                        ->map('Model\Tool::import')
                );

    }
}

namespace Installation {

    use F\PleaseTry;
    use F\Success;
    use Model\BoxBuildCommand;
    use Model\Command;
    use Model\ComposerGlobalInstallCommand;
    use Model\ComposerGlobalMultiInstallCommand;
    use Model\ComposerInstallCommand;
    use Model\MultiStepCommand;
    use Model\PharDownloadCommand;
    use Model\ShCommand;
    use Model\Tool;

    function GetCommand(PleaseTry $tools): Command
    {
        $filterCommands = function ($type) use ($tools) {
            return $tools->map(function (Tool $tool) {
                return $tool->command();
            })->filter(function (Command $command) use ($type) {
                return $command instanceof $type;
            });
        };

        return new MultiStepCommand(
            $filterCommands(ShCommand::class)
                ->merge($filterCommands(PharDownloadCommand::class))
                ->merge($filterCommands(MultiStepCommand::class))
                ->merge(new Success(new ComposerGlobalMultiInstallCommand($filterCommands(ComposerGlobalInstallCommand::class)->get())))
                ->merge($filterCommands(ComposerInstallCommand::class))
                ->merge($filterCommands(BoxBuildCommand::class))
                ->get(),
            ' && ' . PHP_EOL
        );
    }
}

namespace DocUpdate {

    use F\PleaseTry;
    use Model\Tool;

    function UpdateReadme(PleaseTry $tools, string $filePath)
    {
        $toolsList = array_reduce($tools->get(), function ($acc, Tool $tool) {
            return $acc . sprintf('* %s - [%s](%s)', $tool->name(), $tool->summary(), $tool->website()) . PHP_EOL;
        }, '');

        $readme = file_get_contents($filePath);
        $readme = preg_replace('/(## Available tools\n\n).*?(\n## Running tools)/smi', '$1'.$toolsList.'$2', $readme);

        file_put_contents($filePath, $readme);
    }
}

namespace {

    use function Installation\GetCommand;
    use function JsonLoader\LoadTools;
    use function DocUpdate\UpdateReadme;

    $jsonPath = !empty(getenv('TOOLS_JSON')) ? getenv('TOOLS_JSON') : __DIR__ . '/tools.json';
    $action = $argv[1] ?? 'list';
    $tools = LoadTools($jsonPath);

    switch ($action) {
        case 'install':
            print(GetCommand($tools));

            break;
        case 'update-readme':
            $filePath = 'README.md';
            UpdateReadme($tools, $filePath);
            printf('%s was updated.', $filePath);

            break;
        case 'list':
        default:
            print('Available tools:' . PHP_EOL);
            foreach ($tools->get() as $tool) {
                printf('* %s - %s - %s' . PHP_EOL, $tool->name(), $tool->summary(), $tool->website());
            }

            break;
    }
}
