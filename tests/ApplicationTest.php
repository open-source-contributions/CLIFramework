<?php
declare(strict_types = 1);

namespace Tests\Innmind\CLI\Framework;

use Innmind\CLI\Framework\{
    Application,
    KeepVariablesInMemory,
};
use Innmind\CLI\{
    Environment,
    Command,
    Command\Arguments,
    Command\Options,
};
use Innmind\OperatingSystem\{
    OperatingSystem,
    Filesystem,
    CurrentProcess,
    Remote,
};
use Innmind\Filesystem\{
    Adapter\InMemory,
    File\File,
};
use Innmind\DI\Exception\ServiceNotFound;
use Innmind\Server\Status\Server;
use Innmind\Server\Control\Server\Process\Pid;
use Innmind\SilentCartographer\OperatingSystem as SilentCartographer;
use Innmind\Debug\Profiler\Section\CaptureAppGraph;
use Innmind\Stream\Readable\Stream;
use Innmind\Url\Path;
use Innmind\Stream\Writable;
use Innmind\Immutable\{
    Str,
    Sequence,
    Map,
};
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};

class ApplicationTest extends TestCase
{
    use BlackBox;

    public function testRunAHelloWorldByDefault()
    {
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $env
            ->method('output')
            ->willReturn($output = $this->createMock(Writable::class));
        $output
            ->expects($this->once())
            ->method('write')
            ->with(Str::of("Hello world\n"));
        $os = $this->createMock(OperatingSystem::class);

        $app = Application::of($env, $os);
        $app = $app->disableSilentCartographer();

        $this->assertNull($app->run());
    }

    public function testHelloWorldCommandDisappearWhenOneCommandProvided()
    {
        $command = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
                $env->output()->write(Str::of('foo'));
            }

            public function toString(): string
            {
                return 'foo';
            }
        };

        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $env
            ->method('output')
            ->willReturn($output = $this->createMock(Writable::class));
        $output
            ->expects($this->once())
            ->method('write')
            ->with($this->callback(function($text) {
                return !$text->contains('Hello world') &&
                    $text->contains('foo');
            }));
        $os = $this->createMock(OperatingSystem::class);

        $app = Application::of($env, $os);
        $app = $app->disableSilentCartographer();
        $app2 = $app->commands(fn() => [$command]);

        $this->assertInstanceOf(Application::class, $app2);
        $this->assertNotSame($app, $app2);
        $this->assertNull($app2->run());
    }

    public function testListCommandsWhenMoreThanOneProvided()
    {
        $foo = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
            }

            public function toString(): string
            {
                return 'foo';
            }
        };
        $bar = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
            }

            public function toString(): string
            {
                return 'bar';
            }
        };

        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $env
            ->method('output')
            ->willReturn($output = $this->createMock(Writable::class));
        $output
            ->method('write')
            ->with($this->callback(function($text) {
                return !$text->contains('Hello world') &&
                    ($text->contains('foo') || $text->contains('bar'));
            }));
        $os = $this->createMock(OperatingSystem::class);

        $app = Application::of($env, $os);
        $app = $app->disableSilentCartographer();
        $app2 = $app->commands(fn() => [$foo, $bar]);

        $this->assertInstanceOf(Application::class, $app2);
        $this->assertNotSame($app, $app2);
        $this->assertNull($app2->run());
    }

    public function testNoErrorWhenSpecifyingUnknownConfigDirectory()
    {
        $configPath = Path::of('/somewhere/');
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $os = $this->createMock(OperatingSystem::class);
        $os
            ->method('filesystem')
            ->willReturn($filesystem = $this->createMock(Filesystem::class));
        $filesystem
            ->method('contains')
            ->with($configPath)
            ->willReturn(false);
        $command = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
                $env->output()->write(Str::of('foo'));
            }

            public function toString(): string
            {
                return 'foo';
            }
        };

        $app = Application::of($env, $os);
        $app2 = $app
            ->disableSilentCartographer()
            ->commands(fn() => [$command])
            ->configAt($configPath);

        $this->assertInstanceOf(Application::class, $app2);
        $this->assertNotSame($app, $app2);
        $this->assertNull($app2->run());
    }

    public function testNoErrorWhenSpecifyingConfigDirectoryWithoutADotEnvFile()
    {
        $configPath = Path::of('/somewhere/');
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $os = $this->createMock(OperatingSystem::class);
        $os
            ->method('filesystem')
            ->willReturn($filesystem = $this->createMock(Filesystem::class));
        $filesystem
            ->method('contains')
            ->with($configPath)
            ->willReturn(true);
        $filesystem
            ->method('mount')
            ->with($configPath)
            ->willReturn(new InMemory);
        $command = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
                $env->output()->write(Str::of('foo'));
            }

            public function toString(): string
            {
                return 'foo';
            }
        };

        $app = Application::of($env, $os);
        $app2 = $app
            ->disableSilentCartographer()
            ->commands(fn() => [$command])
            ->configAt($configPath);

        $this->assertInstanceOf(Application::class, $app2);
        $this->assertNotSame($app, $app2);
        $this->assertNull($app2->run());
    }

    public function testLoadDotEnv()
    {
        $configPath = Path::of('/somewhere/');
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(
                Map::of('string', 'string')
                    ('FOO', 'bar')
                    ('BAZ', 'bar')
            );
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $os = $this->createMock(OperatingSystem::class);
        $os
            ->method('filesystem')
            ->willReturn($filesystem = $this->createMock(Filesystem::class));
        $filesystem
            ->method('contains')
            ->with($configPath)
            ->willReturn(true);
        $filesystem
            ->method('mount')
            ->with($configPath)
            ->willReturn($config = new InMemory);
        $config->add(File::named(
            '.env',
            Stream::ofContent("FOO=baz\nBAR=foo"),
        ));
        $command = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
                if (!$env instanceof KeepVariablesInMemory) {
                    throw new \Exception('Variables are not kept in memory');
                }

                if ($env->variables()->get('FOO') !== 'baz') {
                    throw new \Exception('Dot env do not override real variables');
                }

                if (!$env->variables()->contains('BAR')) {
                    throw new \Exception('Dot env not loaded');
                }

                if (!$env->variables()->contains('BAZ')) {
                    throw new \Exception('Real variables lost');
                }
            }

            public function toString(): string
            {
                return 'foo';
            }
        };

        $app = Application::of($env, $os);
        $app2 = $app
            ->disableSilentCartographer()
            ->commands(function($env) use ($command) {
                $this->assertInstanceOf(KeepVariablesInMemory::class, $env);
                $this->assertSame('baz', $env->variables()->get('FOO'));
                $this->assertTrue($env->variables()->contains('BAR'), 'Dot env not loaded');
                $this->assertTrue($env->variables()->contains('BAZ'), 'Real variables lost');

                return [$command];
            })
            ->configAt($configPath);

        $this->assertInstanceOf(Application::class, $app2);
        $this->assertNotSame($app, $app2);
        $this->assertNull($app2->run());
    }

    public function testSilentCartographerEnabledByDefaultWithWorkingDirectoryAsRoomLocation()
    {
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $env
            ->method('workingDirectory')
            ->willReturn(Path::of('/working/directory/'));
        $env
            ->method('output')
            ->willReturn($output = $this->createMock(Writable::class));
        $output
            ->expects($this->once())
            ->method('write')
            ->with(Str::of("foo"));
        $os = $this->createMock(OperatingSystem::class);
        $os
            ->method('process')
            ->willReturn($process = $this->createMock(CurrentProcess::class));
        $process
            ->method('id')
            ->willReturn(new Pid(42));
        $os
            ->method('status')
            ->willReturn($status = $this->createMock(Server::class));
        $status
            ->method('tmp')
            ->willReturn(Path::of('/tmp/'));

        $command = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
                $env->output()->write(Str::of('foo'));
            }

            public function toString(): string
            {
                return 'foo';
            }
        };

        $app = Application::of($env, $os);
        $app = $app->commands(function($env, $os) use ($command) {
            $this->assertInstanceOf(SilentCartographer::class, $os);

            return [$command];
        });

        $this->assertNull($app->run());
    }

    public function testAllowToDisableProfilerSections()
    {
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $env
            ->method('output')
            ->willReturn($output = $this->createMock(Writable::class));
        $output
            ->expects($this->once())
            ->method('write')
            ->with(Str::of("foo"));
        $os = $this->createMock(OperatingSystem::class);

        $command = new class implements Command {
            public function __invoke(Environment $env, Arguments $arguments, Options $options): void
            {
                $env->output()->write(Str::of('foo'));
            }

            public function toString(): string
            {
                return 'foo';
            }
        };

        $app = Application::of($env, $os);
        $app2 = $app
            ->disableSilentCartographer()
            ->disableProfilerSection(CaptureAppGraph::class)
            ->commands(fn($env, $os) => [$command]);

        $this->assertInstanceOf(Application::class, $app2);
        $this->assertNotSame($app2, $app);
        $this->assertNull($app2->run());
    }

    public function testUseStandardOperatingSystemAbstractionByDefault()
    {
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $expected = $this->createMock(OperatingSystem::class);

        $app = Application::of($env, $expected)->disableSilentCartographer();
        $app = $app->commands(function($env, $os) use ($expected) {
            $this->assertSame($expected, $os);

            return [];
        });

        $this->assertNull($app->run());
    }

    public function testUseResilientOperatingSystem()
    {
        $env = $this->createMock(Environment::class);
        $env
            ->method('arguments')
            ->willReturn(Sequence::strings());
        $env
            ->method('variables')
            ->willReturn(Map::of('string', 'string'));
        $expected = $this->createMock(OperatingSystem::class);

        $app = Application::of($env, $expected)
            ->useResilientOperatingSystem()
            ->disableSilentCartographer();
        $app = $app->commands(function($env, $os) {
            $this->assertInstanceOf(OperatingSystem\Resilient::class, $os);

            return [];
        });

        $this->assertNull($app->run());
    }

    public function testAbilityToDefineCommandFromAService()
    {
        $this
            ->forAll(
                Set\Unicode::strings(),
                Set\Unicode::strings(),
                Set\Unicode::strings(),
            )
            ->then(function($command, $dep, $toPrint) {
                $env = $this->createMock(Environment::class);
                $env
                    ->method('arguments')
                    ->willReturn(Sequence::strings());
                $env
                    ->method('variables')
                    ->willReturn(Map::of('string', 'string'));
                $env
                    ->method('output')
                    ->willReturn($output = $this->createMock(Writable::class));
                $output
                    ->expects($this->once())
                    ->method('write')
                    ->with(Str::of($toPrint));

                $app = Application::of($env, $this->createMock(OperatingSystem::class))
                    ->disableSilentCartographer()
                    ->command($command)
                    ->service($command, fn($env, $os, $get) => new class($get($dep)) implements Command {
                        private $dep;

                        public function __construct($dep)
                        {
                            $this->dep = $dep;
                        }

                        public function __invoke(Environment $env, Arguments $arguments, Options $options): void
                        {
                            $env->output()->write($this->dep);
                        }

                        public function toString(): string
                        {
                            return 'foo';
                        }
                    })
                    ->service($dep, fn() => Str::of($toPrint));

                $this->assertNull($app->run());
            });
    }

    public function testThrowWhenCommandServiceNotDefined()
    {
        $this
            ->forAll(Set\Unicode::strings())
            ->then(function($command) {
                $env = $this->createMock(Environment::class);
                $env
                    ->method('arguments')
                    ->willReturn(Sequence::strings());
                $env
                    ->method('variables')
                    ->willReturn(Map::of('string', 'string'));

                $app = Application::of($env, $this->createMock(OperatingSystem::class))
                    ->disableSilentCartographer()
                    ->command($command);

                try {
                    $app->run();
                    $this->fail('it should throw');
                } catch (ServiceNotFound $e) {
                    $this->assertSame($command, $e->getMessage());
                }
            });
    }
}
