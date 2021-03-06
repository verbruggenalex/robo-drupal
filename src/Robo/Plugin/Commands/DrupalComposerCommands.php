<?php

namespace VerbruggenAlex\RoboDrupal\Robo\Plugin\Commands;

use Robo\Robo;
use Robo\Common\ResourceExistenceChecker;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DrupalComposerCommands extends \Robo\Tasks
{
    use ResourceExistenceChecker;

  /** @var array */
    private $gitConfig;

  /** @var array $tasks */
    protected $tasks = [];

  /** @var string $composer */
    protected $composer;

    public function __construct()
    {
        Robo::loadConfiguration([__DIR__ . '/../../../config/default.yml']);
    }

  /**
   * Set runtime configuration values.
   *
   * @param \Symfony\Component\Console\Event\ConsoleCommandEvent $event
   *
   * @hook command-event drupal:extend
   */
    public function setRuntimeConfig(ConsoleCommandEvent $event)
    {
        $this->setGitConfig();
        $this->setComposerExecutable();
    }

  /**
   * Extend the composer.json.
   *
   * @return \Robo\Collection\CollectionBuilder
   *   Collection builder.
   *
   * @command drupal:extend
   *
   * @option name         The project name.
   * @option description  The description of the project.
   */
    public function drupalExtend(/** @scrutinizer ignore-unused */ array $options = [
      'name' =>  InputOption::VALUE_OPTIONAL,
      'description' => InputOption::VALUE_OPTIONAL,
    ])
    {
        $list = Robo::Config()->get('list');
        $libDir = __DIR__ . '/../../../../lib';
        $composer = file_exists('composer.json') ? json_decode(file_get_contents('composer.json'), true) : [];
        $composerReqs = [
          'require' => [],
          'require-dev' => [],
        ];

        $options = array_keys($list);
        $question = new ChoiceQuestion(
            'Please select the packages you want to install.',
            $options,
            implode(',', array_keys($options)),
        );
        $question->setMultiselect(true);

        $selection = $this->getDialog()->ask($this->input, $this->output, $question);
        $this->output->writeln('You have just selected: ' . implode(', ', (array) $selection));

        foreach ($selection as $group) {
            foreach ($list[$group] as $requirementType => $requirements) {
                $composerReqs[$requirementType] = array_merge($composerReqs[$requirementType], $requirements);
                foreach ($requirements as $requirement) {
                    $packagePath = str_replace(':', '/', $requirement);
                    $libPackagePath = $libDir . '/' . $packagePath;
                    if (file_exists($libPackagePath)) {
                      // Copy all files except composer.json to the current folder.
                        $this->tasks[] = $this->taskCopyDir([$libDir . '/' . $packagePath => '.'])
                          ->exclude(['composer.json'])
                          ->overwrite(false);
                        $composerJson = $libPackagePath . '/composer.json';
                      // Update composer json array.
                        if (file_exists($composerJson)) {
                            $composerJsonArray = json_decode(file_get_contents($composerJson), true);
                            $composer = $this->arrayMergeRecursiveDistinct($composer, $composerJsonArray);
                        }
                    }
                }
            }
        }

        $this->updateComposerJson($composer);
        $this->tasks[] = $this->taskExecStack()
        ->stopOnFail()
          ->executable($this->composer)
          ->exec('require ' . implode(' ', $composerReqs['require']) . ' --no-update --ansi')
          ->exec('require ' . implode(' ', $composerReqs['require-dev']) . ' --dev --no-update --ansi');

        if ($this->tasks !== []) {
            return $this
            ->collectionBuilder()
            ->addTaskList($this->tasks);
        }
    }

    protected function setGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');
        $cmd = new Process(array($gitBin, 'config', '-l'));
        $cmd->run();

        if ($cmd->isSuccessful()) {
            $this->gitConfig = array();
            preg_match_all('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $this->gitConfig[$match[1]] = $match[2];
            }

            return $this->gitConfig;
        }

        return $this->gitConfig = array();
    }

    protected function setDescription()
    {
        if (!$description = $this->input()->getOption('description') ?: false) {
            $description = $this->getDialog()->ask(
                $this->input(),
                $this->output(),
                new Question(
                    'Description [<comment>' . (string) $description . '</comment>]: ',
                    $description
                )
            );
            $this->input()->setOption('description', $description);
        }

        return $description;
    }

    protected function setName()
    {
        $cwd = realpath(".");

        if (!$name = $this->input()->getOption('name')) {
            $name = basename($cwd);
            $name = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $name = strtolower($name);
            if (!empty($_SERVER['COMPOSER_DEFAULT_VENDOR'])) {
                $name = $_SERVER['COMPOSER_DEFAULT_VENDOR'] . '/' . $name;
            } elseif (isset($this->gitConfig['github.user'])) {
                $name = $this->gitConfig['github.user'] . '/' . $name;
            } elseif (!empty($_SERVER['USERNAME'])) {
                $name = $_SERVER['USERNAME'] . '/' . $name;
            } elseif (!empty($_SERVER['USER'])) {
                $name = $_SERVER['USER'] . '/' . $name;
            } elseif (get_current_user()) {
                $name = get_current_user() . '/' . $name;
            } else {
              // package names must be in the format foo/bar
                $name .= '/' . $name;
            }
            $name = strtolower($name);

            $question = new Question('Package name (<vendor>/<name>) [<comment>' . $name . '</comment>]: ');
            $question->setValidator(
                function ($value) use ($name) {
                    if (null === $value) {
                        return $name;
                    }
                    $this->validateName($value);

                    return $value;
                }
            );
            $name = $this->getDialog()->ask($this->input(), $this->output(), $question);
        } else {
            $this->validateName($name);
        }
        $this->input()->setOption('name', $name);

        return $name;
    }

    protected function validateName($name)
    {
        if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $name)) {
            throw new \InvalidArgumentException(
            // phpcs:ignore Generic.Files.LineLength.TooLong
                'The package name ' . (string) $name . ' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
            );
        }
    }

    public function updateComposerJson($composer)
    {
        // Set project name if it is not set yet.
        if (!array_key_exists('name', $composer)) {
            $composer['name'] = $this->setName();
        }

        // Set project description if it is not set yet.
        if (!array_key_exists('description', $composer)) {
            $composer['description'] = $this->setDescription();
        }

        $json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents('composer.json', $json);
    }

    protected function setComposerExecutable()
    {
        $composer = $this->taskExec('which composer')
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_DEBUG)
        ->run()
        ->getMessage();
        $this->composer = trim($composer);
    }

    protected function arrayMergeRecursiveDistinct()
    {
        $arrays = func_get_args();
        $base = array_shift($arrays);
        if (!is_array($base)) {
            $base = empty($base) ? array() : array($base);
        }
        foreach ($arrays as $append) {
            if (!is_array($append)) {
                $append = array($append);
            }
            foreach ($append as $key => $value) {
                if (!array_key_exists($key, $base) and !is_numeric($key)) {
                    $base[$key] = $append[$key];
                    continue;
                }
                if (is_array($value) or is_array($base[$key])) {
                    $base[$key] = $this->arrayMergeRecursiveDistinct($base[$key], $append[$key]);
                } elseif (is_numeric($key)) {
                    if (!in_array($value, $base)) {
                        $base[] = $value;
                    }
                } else {
                    $base[$key] = $value;
                }
            }
        }
        return $base;
    }
}
