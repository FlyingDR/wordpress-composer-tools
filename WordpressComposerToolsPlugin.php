<?php

namespace Flying\Composer\Plugin;

use Composer\Command\CreateProjectCommand;
use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\InstallerInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Semver\VersionParser;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Flying\Composer\Plugin\IO\AutomatedIO;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class WordpressComposerToolsPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    private $composer;
    /**
     * @var IOInterface
     */
    private $io;
    /**
     * @var string
     */
    private $root;
    /**
     * @var array
     */
    private $gitConfig;
    /**
     * @var array
     */
    private static $wordpressDirectories = [
        'install' => [
            'key'        => 'wordpress-install-dir',
            'title'      => 'Wordpress installation directory',
            'path'       => 'wordpress',
            'allow_root' => false,
        ],
        'content' => [
            'key'        => 'wordpress-content-dir',
            'title'      => 'Wordpress content directory',
            'path'       => 'content',
            'allow_root' => false,
        ],
    ];
    /**
     * @var array
     */
    private static $installerPaths = [
        'type:wordpress-plugin'   => 'composer-plugins',
        'type:wordpress-muplugin' => 'composer-mu-plugins',
        'type:wordpress-theme'    => 'composer-themes',
    ];
    /**
     * @var array
     */
    private static $configurationFiles = [
        'global' => [
            'file'     => 'wp-config.php',
            'type'     => 'wordpress',
            'php'      => true,
            'template' => 'wp-config.php.tpl',
        ],
        'local'  => [
            'file'     => 'local-config.php',
            'type'     => 'wordpress',
            'php'      => true,
            'template' => 'local-config.php.tpl',
        ],
        'apache' => [
            'file'     => '.htaccess',
            'type'     => 'apache',
            'php'      => false,
            'template' => 'htaccess.tpl',
        ],
        'readme' => [
            'file'     => 'README.md',
            'type'     => 'documentation',
            'php'      => false,
            'template' => 'readme.tpl',
        ],
    ];
    /**
     * @var array
     */
    private static $wordpressModules = [
        'plugin' => [
            'directories'   => [
                'src'       => ['root' => 'wp', 'dir' => 'plugins'],
                'project'   => ['root' => 'project', 'dir' => 'plugins'],
                'wordpress' => ['root' => 'project', 'dir' => 'wp-plugins'],
                'composer'  => ['root' => 'project', 'dir' => 'composer-plugins'],
            ],
            'wpcli_command' => 'plugin',
            'name'          => 'plugin',
        ],
        'theme'  => [
            'directories'   => [
                'src'       => ['root' => 'wp', 'dir' => 'themes'],
                'project'   => ['root' => 'project', 'dir' => 'themes'],
                'wordpress' => ['root' => 'project', 'dir' => 'wp-themes'],
                'composer'  => ['root' => 'project', 'dir' => 'composer-themes'],
            ],
            'wpcli_command' => 'theme',
            'name'          => 'theme',
        ],
    ];
    /**
     * @var array
     */
    private $configuredComponents = [];
    /**
     * @var array
     */
    private $variables = [];
    /**
     * @var Filesystem
     */
    private $fs;
    /**
     * @var ProcessExecutor
     */
    private $processExecutor;
    /**
     * @var string|false
     */
    private $wordpressConsole;
    /**
     * @var string
     */
    private $wordpressRoot;
    /**
     * @var string
     */
    private $wpContentDir;
    /**
     * @var string
     */
    private $projectContentDir;
    /**
     * @var boolean
     */
    private $isCreatingProject;
    /**
     * @var array
     */
    private $newPackages = [];
    /**
     * @var array
     */
    private $removedPackages = [];

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->newPackages = [];
        $this->removedPackages = [];
    }

    /**
     * @return Composer
     */
    private function getComposer()
    {
        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    private function getIO()
    {
        return $this->io;
    }

    /**
     * @return string
     */
    private function getProjectRoot()
    {
        if (!$this->root) {
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            $this->root = $this->getFilesystem()->normalizePath(dirname($this->getComposer()->getConfig()->get('vendor-dir')));
        }
        return $this->root;
    }

    /**
     * @return ProcessExecutor
     */
    private function getProcessExecutor()
    {
        if (!$this->processExecutor) {
            $this->processExecutor = new ProcessExecutor($this->getIO());
        }
        return $this->processExecutor;
    }

    /**
     * @return Filesystem
     */
    private function getFilesystem()
    {
        if (!$this->fs) {
            $this->fs = new Filesystem($this->getProcessExecutor());
        }
        return $this->fs;
    }

    /**
     * @return string
     */
    private function getWordpressRootDirectory()
    {
        if (!$this->wordpressRoot) {
            $this->wordpressRoot = $this->getFilesystem()->normalizePath($this->getProjectRoot() . '/' . $this->getComposer()->getPackage()->getExtra()[self::$wordpressDirectories['install']['key']]);
        }
        return $this->wordpressRoot;
    }

    /**
     * @return string
     */
    private function getWordpressContentDirectory()
    {
        if (!$this->wpContentDir) {
            $this->wpContentDir = $this->getFilesystem()->normalizePath($this->getWordpressRootDirectory() . '/wp-content');
        }
        return $this->wpContentDir;
    }

    /**
     * @return string
     */
    private function getProjectContentDirectory()
    {
        if (!$this->projectContentDir) {
            $this->projectContentDir = $this->getFilesystem()->normalizePath($this->getProjectRoot() . '/' . $this->getComposer()->getPackage()->getExtra()[self::$wordpressDirectories['content']['key']]);
        }
        return $this->projectContentDir;
    }

    /**
     * Get path to given type of directory for given type of Wordpress module
     *
     * @param string $moduleType
     * @param string $dirType
     * @return string
     * @throws \InvalidArgumentException
     */
    private function getWordpressModulesDirectory($moduleType, $dirType)
    {
        if (!array_key_exists($moduleType, self::$wordpressModules)) {
            throw new \InvalidArgumentException('Unknown content type: ' . $moduleType);
        }
        if (!array_key_exists($dirType, self::$wordpressModules[$moduleType]['directories'])) {
            throw new \InvalidArgumentException('Unknown content directory type: ' . $dirType);
        }
        $info = self::$wordpressModules[$moduleType]['directories'][$dirType];
        return (($info['root'] === 'wp') ? $this->getWordpressContentDirectory() : $this->getProjectContentDirectory()) . '/' . $info['dir'];
    }

    /**
     * Determine if we're into "create-project" command
     *
     * @return boolean
     */
    private function isCreatingProject()
    {
        if ($this->isCreatingProject === null) {
            $this->isCreatingProject = false;
            $backtrace = debug_backtrace();
            foreach ($backtrace as $item) {
                if (!array_key_exists('object', $item)) {
                    continue;
                }
                if ($item['object'] instanceof CreateProjectCommand) {
                    $this->isCreatingProject = true;
                    break;
                }
            }
        }
        return $this->isCreatingProject;
    }

    /**
     * Determine if we're operating on own project
     *
     * @return boolean
     */
    private function isOwnProject()
    {
        return $this->getComposer()->getPackage()->getPrettyName() === 'flying/wordpress-composer';
    }

    public function onCreateProject()
    {
        $this->configuredComponents = [];
        $this->variables = [];
        if ($this->isOwnProject()) {
            // Look if we have answers.json file around
            $paths = [
                $this->getProjectRoot(),
                $this->getProjectRoot() . '/..',
            ];
            foreach ($paths as $path) {
                $path .= '/answers.json';
                $path = $this->getFilesystem()->normalizePath($path);
                $json = new JsonFile($path);
                if ($json->exists()) {
                    $json = $json->read();
                    if (is_array($json)) {
                        $this->io = new AutomatedIO($this->getIO(), $json);
                    }
                }
            }
            $this->configureWordpressDirectories();
        }
        $this->configureComposer();
        $this->createWordpressConfig();
        $this->installWordpress();
        $this->handleWordpressModules();
    }

    public function onPostInstall()
    {
        if (!$this->isCreatingProject() && !$this->isOwnProject()) {
            $this->handleWordpressModules();
        }
    }

    public function onPostUpdate()
    {
        if (!$this->isOwnProject()) {
            $this->handleWordpressModules();
        }
    }

    public function onPrePackageInstall(PackageEvent $event)
    {
        /** @var InstallOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getPackage()->getName();
        if (!preg_match('/^wpackagist-([^\/]+)\/(.+)$/', $package, $m)) {
            return;
        }
        $type = $m[1];
        $module = $m[2];
        if (!array_key_exists($type, $this->newPackages)) {
            $this->newPackages[$type] = [];
        }
        $this->newPackages[$type][] = $module;
    }

    public function onPrePackageUninstall(PackageEvent $event)
    {
        /** @var InstallOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getPackage()->getName();
        if (!preg_match('/^wpackagist-([^\/]+)\/(.+)$/', $package, $m)) {
            return;
        }
        $type = $m[1];
        $module = $m[2];
        if (!array_key_exists($type, $this->removedPackages)) {
            $this->removedPackages[$type] = [];
        }
        $this->removedPackages[$type][] = $module;
        // We should remove symlink to package from Wordpress modules directory
        // because after package itself will be removed by Composer this link will became broken
        // and it will not be visible to DirectoryIterator
        $this->getFilesystem()->removeDirectory($this->getWordpressModulesDirectory($type, 'project') . '/' . $module);
    }

    /**
     * Configure main directories where Wordpress will be installed
     */
    private function configureWordpressDirectories()
    {
        try {
            $composer = $this->getComposer();
            $fs = $this->getFilesystem();
            $projectRoot = $fs->normalizePath($this->getProjectRoot());
            // By this time we already have Wordpress installed. However if we currently have different path for it - we need to move it to new location
            $wpInstallPath = null;
            try {
                $package = $composer->getRepositoryManager()->getLocalRepository()->findPackage('johnpbloch/wordpress', new EmptyConstraint());
                if ($package instanceof PackageInterface) {
                    $installer = $composer->getInstallationManager()->getInstaller('wordpress-core');
                    if ($installer instanceof InstallerInterface) {
                        $path = $installer->getInstallPath($package);
                        if (!$fs->isAbsolutePath($path)) {
                            $path = $projectRoot . '/' . $path;
                        }
                        $path = $fs->normalizePath($path);
                        if (!$fs->isDirEmpty($path)) {
                            $wpInstallPath = $path;
                        }
                    }
                }
            } catch (\InvalidArgumentException $e) {

            }
            $package = $composer->getPackage();
            $extra = $package->getExtra();
            foreach (self::$wordpressDirectories as $dirId => $dirInfo) {
                $key = $dirInfo['key'];
                $dir = $dirInfo['path'];
                if (array_key_exists($key, $extra)) {
                    $dir = $extra[$key];
                }
                if ($this->io->isInteractive()) {
                    $question = array_key_exists('question', $dirInfo) ? $dirInfo['question'] : $dirInfo['title'];
                    $dir = $this->io->askAndValidate(sprintf('%s [<comment>%s</comment>]: ', $question, $dir), function ($value) use ($dirId, $dirInfo, $dir, $wpInstallPath, $projectRoot, $fs) {
                        if ($value === '') {
                            $value = $dir;
                        }
                        $title = $dirInfo['title'];
                        if ($fs->isAbsolutePath($value)) {
                            throw new \InvalidArgumentException(sprintf('%s should be defined as relative path', $title));
                        }
                        $path = $fs->normalizePath($this->getProjectRoot() . '/' . $value);
                        if (strpos($path, $projectRoot) !== 0) {
                            throw new \InvalidArgumentException(sprintf('%s should reside within project root', $title));
                        }
                        if ($value === '.' && !$dirInfo['allow_root']) {
                            throw new \InvalidArgumentException(sprintf('%s can\'t match project root', $title));
                        }
                        // Project root directory and current Wordpress installation directories are available, in other cases it is a problem
                        if ($value !== '.' && ($dirId !== 'install' || ($wpInstallPath === null || $path !== $wpInstallPath)) && file_exists($path)) {
                            throw new \InvalidArgumentException(sprintf('%s is already exists', $title));
                        }
                        return preg_replace('/^\.\//', '', $fs->findShortestPath($projectRoot, $path, true));
                    }, null, $dir);
                }
                $extra[$key] = $dir;
            }
            $package->setExtra($extra);
            // If Wordpress is configured to be located into different place then it is already installed - move it
            if ($wpInstallPath) {
                $wpTargetPath = $fs->normalizePath($projectRoot . '/' . $extra[self::$wordpressDirectories['install']['key']]);
                if ($wpInstallPath !== $wpTargetPath) {
                    $fs->rename($wpInstallPath, $wpTargetPath);
                }
            }
        } catch (\Exception $e) {

        }
    }

    /**
     * Update composer.json to prepare it to use by newly created project
     * Parts of code of this function are taken from Composer itself because of similar functionality
     *
     * @see Composer\Command\InitCommand::interact
     */
    private function configureComposer()
    {
        try {
            $io = $this->getIO();
            $composerJson = new JsonFile($this->getProjectRoot() . '/composer.json');
            if (!$composerJson->exists()) {
                $io->write('<comment>composer.json is not found, skipping its configuration, you need to create it later</comment>');
                return;
            }
            try {
                /** @var array $config */
                $config = $composerJson->read();
            } catch (\RuntimeException $e) {
                $io->write('<error>composer.json is not valid, skipping its configuration, you need to create it later</error>');
                return;
            }
            $config['name'] = '';
            $config['description'] = '';
            $config['authors'] = [];
            unset($config['version'], $config['type'], $config['keywords'], $config['homepage'], $config['time'], $config['license'], $config['support'], $config['require-dev']);
            if ($io->isInteractive()) {
                $io->write('<info>composer.json will be modified now to match your project settings</info>');
                // Get package name 
                $git = $this->getGitConfig();
                $name = basename($this->getProjectRoot());
                $name = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
                $name = strtolower($name);
                if (array_key_exists('github.user', $git)) {
                    $name = $git['github.user'] . '/' . $name;
                } elseif (!empty($_SERVER['USERNAME'])) {
                    $name = $_SERVER['USERNAME'] . '/' . $name;
                } elseif (get_current_user()) {
                    $name = get_current_user() . '/' . $name;
                } else {
                    // package names must be in the format foo/bar
                    $name = $name . '/' . $name;
                }
                $name = strtolower($name);
                $name = $io->askAndValidate(
                    'Package name (<vendor>/<name>) [<comment>' . $name . '</comment>]: ',
                    function ($value) use ($name) {
                        if (null === $value) {
                            return $name;
                        }

                        if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}', $value)) {
                            throw new \InvalidArgumentException(
                                'The package name ' . $value . ' is invalid, it should be lowercase and have a vendor name, a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+'
                            );
                        }

                        return $value;
                    },
                    null,
                    $name
                );
                $config['name'] = $name;
                $this->variables['package-name'] = [
                    'type'  => 'string',
                    'value' => $name,
                ];

                // Get package description
                $description = '';
                $description = $io->ask('Description [<comment>' . $description . '</comment>]: ', $description);
                $config['description'] = $description;
                $this->variables['package-description'] = [
                    'type'  => 'string',
                    'value' => $description,
                ];

                // Get package author
                $author = '';
                $parseAuthor = function ($author) {
                    if (preg_match('/^(?P<name>[- \.,\p{L}\p{N}\'’]+) <(?P<email>.+?)>$/u', $author, $match) && filter_var($match['email'], FILTER_VALIDATE_EMAIL) !== false) {
                        return [
                            'name'  => trim($match['name']),
                            'email' => $match['email'],
                        ];
                    }
                    return null;
                };
                $formatAuthor = function ($name, $email) {
                    return sprintf('%s <%s>', $name, $email);
                };
                if (array_key_exists('user.name', $git) && array_key_exists('user.email', $git)) {
                    $author = $formatAuthor($git['user.name'], $git['user.email']);
                    if (!$parseAuthor($author)) {
                        $author = '';
                    }
                }
                $author = $io->askAndValidate('Author [<comment>' . $author . '</comment>, n to skip]: ', function ($value) use ($parseAuthor, $formatAuthor, $author) {
                    if ($value === 'n' || $value === 'no') {
                        return null;
                    }
                    $value = $value ?: $author;
                    $author = $parseAuthor($value);
                    if (!is_array($author)) {
                        throw new \InvalidArgumentException(
                            'Invalid author string.  Must be in the format: ' .
                            'John Smith <john@example.com>'
                        );
                    }
                    return $formatAuthor($author['name'], $author['email']);
                }, null, $author);
                if ($author) {
                    $config['authors'][] = $parseAuthor($author);
                }
            } else {
                $io->write('<comment>composer.json is cleaned up, but not configured because installation is running in non-interactive mode. You need to configure it by yourself</comment>');
            }

            // Setup WPackagist repository
            if (!array_key_exists('repositories', $config)) {
                $config['repositories'] = [];
            }
            $wpackagist = [
                'type' => 'composer',
                'url'  => 'https://wpackagist.org',
            ];
            foreach ($config['repositories'] as $repository) {
                if (array_key_exists('url', $repository) &&
                    array_key_exists('type', $repository) &&
                    $repository['type'] === $wpackagist['type'] &&
                    $repository['url'] === $wpackagist['url']
                ) {
                    $wpackagist = null;
                    break;
                }
            }
            if ($wpackagist !== null) {
                $config['repositories'][] = $wpackagist;
            }

            // Setup Wordpress directories and Wordpress installers paths
            if (!array_key_exists('extra', $config)) {
                $config['extra'] = [];
            }
            $extra = $this->getComposer()->getPackage()->getExtra();
            $gitIgnore = [
                '# Wordpress itself and related files and directories',
            ];
            $gitIgnore[] = '/' . self::$configurationFiles['local']['file'];
            $directories = [];
            foreach (self::$wordpressDirectories as $type => $directory) {
                $key = $directory['key'];
                $dir = $directory['path'];
                if (array_key_exists($key, $extra)) {
                    $dir = $extra[$key];
                }
                $directories[$type] = $dir;
                $config['extra'][$key] = $dir;
                $this->variables[$key] = [
                    'type'  => 'string',
                    'value' => $dir,
                ];
                if ($type !== 'content') {
                    $gitIgnore[] = '/' . $dir;
                }
            }
            // Ignore vendor and bin directories that are controlled by Composer
            $composerCfg = $this->getComposer()->getConfig();
            $vendorDir = $composerCfg->get('vendor-dir', Config::RELATIVE_PATHS);
            $gitIgnore[] = '/' . $vendorDir;
            $binDir = $composerCfg->get('bin-dir', Config::RELATIVE_PATHS);
            if ($binDir !== 'vendor/bin') {
                if (!array_key_exists('config', $config)) {
                    $config['config'] = [];
                }
                $config['config']['bin-dir'] = $binDir;
            }
            if (!in_array($binDir, ['', '.'], true) && strpos($binDir, $vendorDir . '/') !== 0) {
                $gitIgnore[] = '/' . $binDir;
            }
            $gitIgnore[] = '';
            $gitIgnore[] = '# Directories for Wordpress plugins and themes controlled by Composer';
            $config['extra']['installer-paths'] = [];
            foreach (self::$installerPaths as $type => $dir) {
                $path = sprintf('%s/%s/{$name}', $directories['content'], $dir);
                $config['extra']['installer-paths'][$path] = [$type];
                $gitIgnore[] = sprintf('/%s/%s', $directories['content'], $dir);
            }
            $root = $this->getProjectRoot();
            foreach (self::$wordpressModules as $type => $info) {
                $gitIgnore[] = '/' . ltrim(str_replace($root, '', $this->getWordpressModulesDirectory($type, 'project')), '/');
            }

            $composerJson->write($config);
            $io->write('<info>composer.json is successfully updated</info>');
            $this->configuredComponents['composer'] = $config;

            // Create .gitignore
            file_put_contents($this->getProjectRoot() . '/.gitignore', implode("\n", $gitIgnore));
            $this->configuredComponents['gitignore'] = $gitIgnore;
        } catch (\Exception $e) {
            $this->getIO()->writeError('composer.json configuration failed due to exception: ' . $e->getMessage());
        }
    }

    /**
     * Taken from Composer sources
     *
     * @see Composer\Command\InitCommand::getGitConfig
     * @return array
     */
    private function getGitConfig()
    {
        if (null !== $this->gitConfig) {
            return $this->gitConfig;
        }

        try {
            $finder = new ExecutableFinder();
            $gitBin = $finder->find('git');

            $cmd = new Process(sprintf('%s config -l', ProcessExecutor::escape($gitBin)));
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
        } catch (\Exception $e) {

        }
        return array();
    }

    /**
     * Create Wordpress configuration files upon creation of new project
     */
    private function createWordpressConfig()
    {
        try {
            $io = $this->getIO();
            foreach (self::$configurationFiles as $item) {
                if ($item['type'] !== 'wordpress') {
                    continue;
                }
                if (file_exists($this->getProjectRoot() . '/' . $item['file'])) {
                    $io->write('<comment>' . $item['file'] . ' configuration file is already exists, skipping</comment>');
                    return;
                }
            }

            // Generate keys and salts
            $keysAndSalts = [
                'AUTH_KEY',
                'SECURE_AUTH_KEY',
                'LOGGED_IN_KEY',
                'NONCE_KEY',
                'AUTH_SALT',
                'SECURE_AUTH_SALT',
                'LOGGED_IN_SALT',
                'NONCE_SALT',
            ];
            $this->configuredComponents['auth-keys'] = [];
            foreach ($keysAndSalts as $key) {
                $value = $this->generateSecureString(64, true);
                $this->variables[$key] = [
                    'type'  => 'constant',
                    'value' => $value,
                ];
                $this->configuredComponents['auth-keys'][$key] = $value;
            }

            // Database tables prefix should be defined in any way
            $this->variables['table_prefix'] = [
                'type'  => 'variable',
                'value' => 'wp_',
            ];
            if ($io->isInteractive()) {
                // Database connection configuration
                if ($io->askConfirmation('<info>Do you want to configure database connection parameters?</info> [<comment>Y,n</comment>]: ', true)) {
                    $databaseParameters = [
                        [
                            'name'      => 'DB_HOST',
                            'type'      => 'constant',
                            'question'  => 'Database hostname',
                            'default'   => 'localhost',
                            'validator' => function ($value) {
                                if (filter_var('http://' . $value . '/', FILTER_VALIDATE_URL) === false) {
                                    throw new \InvalidArgumentException('Invalid database host name');
                                }
                                return $value;
                            }
                        ],
                        [
                            'name'      => 'DB_NAME',
                            'type'      => 'constant',
                            'question'  => 'Database name',
                            'validator' => function ($value) {
                                $value = trim($value);
                                if ($value !== '' && (!preg_match('/^[^\x00\xff\x5c\/\.\:]+$/i', $value))) {
                                    throw new \InvalidArgumentException('Database name can\'t contain special characters');
                                }
                                return $value;
                            }
                        ],
                        [
                            'name'      => 'DB_USER',
                            'type'      => 'constant',
                            'question'  => 'Database user name',
                            'validator' => function ($value) {
                                if (strlen($value) > 32) {
                                    throw new \InvalidArgumentException('Database user name can\'t exceed 32 characters');
                                }
                                return $value;
                            }
                        ],
                        [
                            'name'     => 'DB_PASSWORD',
                            'type'     => 'constant',
                            'question' => 'Database user password',
                        ],
                        [
                            'name'     => 'DB_CHARSET',
                            'type'     => 'constant',
                            'question' => 'Database charset',
                            'default'  => 'utf8',
                        ],
                        [
                            'name'    => 'DB_COLLATE',
                            'type'    => 'constant',
                            'default' => '',
                        ],
                        [
                            'name'      => 'table_prefix',
                            'type'      => 'variable',
                            'question'  => 'Database tables prefix',
                            'default'   => 'wp_',
                            'validator' => function ($value) {
                                if ($value !== '' && substr($value, -1) !== '_') {
                                    $value .= '_';
                                }
                                return $value;
                            }
                        ],
                    ];
                    foreach ($databaseParameters as $var) {
                        $default = array_key_exists('default', $var) ? $var['default'] : null;
                        if (array_key_exists('question', $var)) {
                            $question = $var['question'] . ($default !== null ? ' [<comment>' . $default . '</comment>]' : '') . ': ';
                            if (array_key_exists('validator', $var)) {
                                $value = $io->askAndValidate($question, $var['validator'], null, $default);
                            } else {
                                $value = $io->ask($question, $default);
                            }
                        } else {
                            $value = $default;
                        }
                        $result = [
                            'type'  => $var['type'],
                            'value' => (string)$value,
                        ];
                        $this->variables[$var['name']] = $result;
                        $this->configuredComponents['database'][$var['name']] = $var;
                    }
                }

                // Site URL configuration
                if ($io->askConfirmation('<info>Do you want to configure site URL parameters?</info> [<comment>Y,n</comment>]: ', true)) {
                    $siteUrl = $io->askAndValidate('Enter URL of home page of this Wordpress site: ', [$this, 'validateUrl']);
                    $this->configuredComponents['site-urls'] = [];
                    $this->variables['WP_SITEURL'] = [
                        'type'  => 'constant',
                        'value' => $siteUrl,
                    ];
                    $this->configuredComponents['site-urls']['WP_SITEURL'] = $siteUrl;
                    $p = parse_url($siteUrl);
                    $this->variables['WP_HOME'] = [
                        'type'  => 'constant',
                        'value' => $p['scheme'] . '://' . $p['host'] . (array_key_exists('port', $p) ? ':' . $p['port'] : ''),
                    ];
                    $this->configuredComponents['site-urls']['WP_HOME'] = $this->variables['WP_HOME']['value'];
                }
            } else {
                $io->write('<comment>Wordpress configuration file is created, but no details was configured because installation is running in non-interactive mode. You need to review and update it by yourself</comment>');
            }

            // Generate configuration files
            foreach (self::$configurationFiles as $configId => $configuration) {
                if ($configId === 'local' && !$io->isInteractive()) {
                    // Local configuration is useless without interactive questionnaire
                    continue;
                }
                $templatePath = __DIR__ . '/templates/' . $configuration['template'];
                $template = null;
                if (is_file($templatePath)) {
                    $template = file_get_contents($templatePath);
                }
                if (!is_string($template) || $template === '') {
                    $io->write(sprintf('<comment>No template are available for %s</comment>', $configuration['file']));
                    continue;
                }
                $ldelim = $configuration['php'] ? '\/\*\s*\{\s*' : '\{\{\s*';
                $rdelim = $configuration['php'] ? '\s*\}\s*\*\/' : '\s*\}\}';
                foreach ($this->variables as $name => $var) {
                    $value = $var['value'];
                    if (is_string($value)) {
                        $value = addslashes($var['value']);
                        if ($var['type'] !== 'string') {
                            $value = "'" . $value . "'";
                        }
                    } elseif ($value === null) {
                        $value = 'null';
                    } elseif ($value === true) {
                        $value = 'true';
                    } elseif ($value === false) {
                        $value = 'false';
                    }
                    switch ($var['type']) {
                        case 'constant':
                            $code = sprintf("define('%s', %s);", $name, $value);
                            break;
                        case 'variable':
                            $code = sprintf("$%s = %s;", $name, $value);
                            break;
                        case 'string':
                        default:
                            $code = $value;
                            break;
                    }
                    $template = preg_replace(sprintf('/%s%s%s/usi', $ldelim, $name, $rdelim), $code, $template);
                }
                $template = preg_replace(sprintf('/%s.+?%s/usi', $ldelim, $rdelim), '', $template);
                if ($configId === 'local') {
                    // There may be missed entries into local configuration file
                    $template = preg_replace('/(\r?\n){2,}/i', "\n\n", $template);
                }
                $configPath = $this->getProjectRoot() . '/' . $configuration['file'];
                file_put_contents($configPath, $template);
                if (!file_exists($configPath)) {
                    throw new \RuntimeException('Failed to write ' . $configuration['file']);
                }
                $tmp = tempnam(sys_get_temp_dir(), 'wpskt');
                $exitcode = $this->getProcessExecutor()->execute(sprintf('%s -l %s > %s', PHP_BINARY, escapeshellarg($configPath), $tmp));
                unlink($tmp);
                if ($exitcode !== 0) {
                    unlink($configPath);
                    throw new \RuntimeException('Failed to generate ' . $configuration['file']);
                }
            }
            $io->write('<info>Wordpress configuration files are successfully created</info>');
        } catch (\Exception $e) {
            $this->getIO()->writeError(sprintf('<error>Wordpress configuration files generation failed: %s</error>', $e->getMessage()));
        }
    }

    /**
     * Validate and normalize URL received from interactive question
     *
     * @param string $value
     * @return string
     * @throws \InvalidArgumentException
     */
    public function validateUrl($value)
    {
        if (strpos($value, '://') === false) {
            $value = 'http://' . $value;
        }
        $p = parse_url($value);
        if (!is_array($p)) {
            throw new \InvalidArgumentException('Invalid URL');
        }
        if (!array_key_exists('host', $p)) {
            throw new \InvalidArgumentException('Invalid URL, no host name is found');
        }
        if (array_key_exists('query', $p)) {
            throw new \InvalidArgumentException('Invalid URL, no query string should be included');
        }
        if (array_key_exists('user', $p) || array_key_exists('pass', $p)) {
            throw new \InvalidArgumentException('Invalid URL, no access credentials should be included');
        }
        if (!array_key_exists('scheme', $p)) {
            $p['scheme'] = 'http';
        }
        if (array_key_exists('path', $p)) {
            $p['path'] = rtrim($p['path'], '/');
        }
        return $p['scheme'] . '://' . $p['host'] . (array_key_exists('port', $p) ? ':' . $p['port'] : '') . (array_key_exists('path', $p) ? ':' . $p['path'] : '/');
    }

    /**
     * Generate secure string with given criteria
     *
     * @param int $length
     * @param boolean $strong
     * @return string
     */
    private function generateSecureString($length = 64, $strong = true)
    {
        if (!function_exists('random_int')) {
            // By default we use secure random_int() function to generate keys and salts
            // However it is only available in PHP7 natively, so for PHP5 we should try to use its PHP version from include compatibility package
            // Since at a time of package creation no packages are loaded - let's try to load it by ourselves
            try {
                $package = $this->getComposer()->getRepositoryManager()->getLocalRepository()->findPackage('paragonie/random_compat', new EmptyConstraint());
                if ($package instanceof PackageInterface) {
                    $installPath = $this->getComposer()->getInstallationManager()->getInstallPath($package);
                    $fs = $this->getFilesystem();
                    foreach ($package->getAutoload() as $type => $includes) {
                        if ($type !== 'files') {
                            continue;
                        }
                        foreach ((array)$includes as $include) {
                            $path = $fs->normalizePath($installPath . '/' . $include);
                            if (file_exists($path)) {
                                /** @noinspection PhpIncludeInspection */
                                include_once $path;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {

            }
        }
        $chars = range(chr(33), chr(126));
        if (!$strong) {
            $chars = [];
            $symbols = [range('!', '.'), range(':', '@'), range('[', '_'), range('{', '~')];
            $letters = [range('0', '9'), range('A', 'Z'), range('a', 'z')];
            foreach ($symbols as $r) {
                $chars[] = $r;
                /** @noinspection DisconnectedForeachInstructionInspection */
                foreach ($letters as $l) {
                    $chars[] = $l;
                }
            }
            $chars = call_user_func_array('array_merge', $chars);
        }
        $count = count($chars);
        $string = '';
        do {
            $c = $chars[function_exists('random_int') ? random_int(0, $count - 1) : mt_rand(0, $count - 1)];
            if (!in_array($c, ["'", '"', '\\'], true)) {
                $string .= $c;
            }
        } while (strlen($string) < $length);
        return $string;
    }

    /**
     * Install Wordpress
     */
    private function installWordpress()
    {
        try {
            $io = $this->getIO();
            if (!$this->isWordpressInstalled()) {
                if ($io->isInteractive()) {
                    $requiredComponents = ['auth-keys', 'database'];
                    /** @noinspection NotOptimalIfConditionsInspection */
                    if (count(array_intersect(array_keys($this->configuredComponents), $requiredComponents)) === count($requiredComponents) &&
                        $io->askConfirmation('<info>Do you want to install Wordpress?</info> [<comment>Y,n</comment>]: ', true)
                    ) {
                        $installQuestionnaire = [
                            [
                                'param'     => 'url',
                                'question'  => 'Enter site URL',
                                'default'   => function () {
                                    if (array_key_exists('site-urls', $this->configuredComponents) && array_key_exists('WP_SITEURL', $this->configuredComponents['site-urls'])) {
                                        return $this->configuredComponents['site-urls']['WP_SITEURL'];
                                    }
                                    return null;
                                },
                                'validator' => [$this, 'validateUrl'],
                            ],
                            [
                                'param'    => 'title',
                                'question' => 'Enter site title',
                                'default'  => '',
                            ],
                            [
                                'param'     => 'admin_user',
                                'question'  => 'Enter admin login',
                                'default'   => 'admin',
                                'validator' => function ($value) {
                                    $value = trim($value);
                                    if (!preg_match('/^[a-z0-9\_\.\-\@]{1,64}$/', $value)) {
                                        throw new \InvalidArgumentException('Invalid login, it should contain only lower case characters, numbers and symbols "_", "-", ".", "@"');
                                    }
                                    return $value;
                                }
                            ],
                            [
                                'param'    => 'admin_password',
                                'question' => 'Enter admin password',
                                'default'  => $this->generateSecureString(20, false),
                            ],
                            [
                                'param'     => 'admin_email',
                                'question'  => 'Enter admin email',
                                'default'   => function () {
                                    if (array_key_exists('composer', $this->configuredComponents) && array_key_exists('authors', $this->configuredComponents['composer'])) {
                                        $t = $this->configuredComponents['composer']['authors'];
                                        $t = array_shift($t);
                                        return $t['email'];
                                    }
                                    return null;
                                },
                                'validator' => function ($value) {
                                    $value = filter_var($value, FILTER_VALIDATE_EMAIL);
                                    if ($value === false) {
                                        throw new \InvalidArgumentException('Invalid email address');
                                    }
                                    return $value;
                                }
                            ],
                        ];
                        $installArgs = [];
                        foreach ($installQuestionnaire as $var) {
                            $default = array_key_exists('default', $var) ? $var['default'] : null;
                            if (is_callable($default)) {
                                $default = $default();
                            }
                            if (array_key_exists('question', $var)) {
                                $question = $var['question'] . ($default !== null ? ' [<comment>' . $default . '</comment>]' : '') . ': ';
                                if (array_key_exists('validator', $var)) {
                                    $value = $io->askAndValidate($question, $var['validator'], null, $default);
                                } else {
                                    $value = $io->ask($question, $default);
                                }
                            } else {
                                $value = $default;
                            }
                            if (array_key_exists('param', $var)) {
                                $installArgs[$var['param']] = $value;
                            } else {
                                $installArgs[] = $value;
                            }
                        }
                        // Install Wordpress
                        $io->write('<info>Installing Wordpress...</info>');
                        $this->runWpCliCommand('db', 'create', [], $output, $error);
                        if ($this->runWpCliCommand('core', 'install', $installArgs, $output, $error)) {
                            $io->write('<comment>Wordpress is successfully installed</comment>');
                        } else {
                            $io->writeError('<error>Failed to install Wordpress</error>');
                        }
                    }
                } else {
                    $command = str_replace($this->getProjectRoot() . '/', '', $this->getWordpressConsole());
                    $command = Platform::isWindows() ? str_replace('/', '\\', $command) : './' . $command;
                    $command .= ' core install';
                    $io->write(sprintf('<info>Wordpress installation is skipped because installation is running in non-interactive mode. Run install either through web or by preparing all required configuration parameters and calling <comment>%s</comment></info>', $command));
                }
            } else {
                $io->write('<comment>Wordpress is already installed</comment>');
            }
        } catch (\Exception $e) {
            $this->getIO()->writeError(sprintf('<error>Wordpress installation failed: %s</error>', $e->getMessage()));
        }
    }

    /**
     * Handle directories where Wordpress modules are stored
     */
    private function handleWordpressModules()
    {
        $io = $this->getIO();
        if (!$this->isWordpressInstalled()) {
            $io->write('<comment>Wordpress modules handling is skipped because Wordpress itself is not installed yet</comment>');
            return;
        }
        try {
            $composerJson = new JsonFile($this->getProjectRoot() . '/composer.json');
            if (!$composerJson->exists()) {
                throw new \RuntimeException('composer.json is not available');
            }
            try {
                /** @var array $composerConfig */
                $composerConfig = $composerJson->read();
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('composer.json is not valid');
            }
        } catch (\Exception $e) {
            $io->writeError('<comment>composer.json is either missed or not valid, skipping Wordpress modules configuration</comment>');
            return;
        }
        $configHash = sha1(serialize($composerConfig));
        $fs = $this->getFilesystem();
        // Wordpress modules are handled by creating project's content directory for each module type
        // with symlinks to modules themselves which, in its turn is symlinked into Wordpress content directory 
        foreach (self::$wordpressModules as $moduleType => $moduleInfo) {
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            $srcDir = $this->getWordpressModulesDirectory($moduleType, 'src');
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            $projectDir = $this->getWordpressModulesDirectory($moduleType, 'project');
            $isFreshInstall = false;
            if (is_dir($srcDir) && !$this->isSymlink($srcDir)) {
                $fs->rename($srcDir, $projectDir);
                $isFreshInstall = true;
            }
            $this->convertWordpressModules($moduleType, $composerConfig, $isFreshInstall);
            if (!$this->isSymlink($srcDir)) {
                $this->symlink($srcDir, $projectDir);
            }
        }
        if (sha1(serialize($composerConfig)) !== $configHash) {
            try {
                $composerJson->write($composerConfig);
                $io->write('<info>Composer configuration is updated to include Wordpress modules updates</info>');
            } catch (\Exception $e) {
                $io->writeError('<error>Failed to update Composer configuration file</error>');
            }
        }
        // Create symlinked version of "uploads" directory
        $src = $this->getWordpressContentDirectory() . '/uploads';
        $dest = $this->getProjectContentDirectory() . '/uploads';
        if (is_dir($src) && !$this->isSymlink($src)) {
            $fs->rename($src, $dest);
        }
        $fs->ensureDirectoryExists($dest);
        if (!$this->isSymlink($src)) {
            $this->symlink($src, $dest);
        }
    }

    /**
     * Convert given type of Wordpress modules that was installed through Wordpress itself into Composer packages
     *
     * @param string $moduleType
     * @param array $composerConfig
     * @param boolean $isFreshInstall
     * @return bool
     */
    private function convertWordpressModules($moduleType, array &$composerConfig, $isFreshInstall)
    {
        $io = $this->getIO();
        $fs = $this->getFilesystem();
        $typeName = self::$wordpressModules[$moduleType]['name'];
        $modulePrefix = sprintf('wpackagist-%s/', $moduleType);
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $modulesDir = $this->getWordpressModulesDirectory($moduleType, 'project');
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $wpModulesDir = $this->getWordpressModulesDirectory($moduleType, 'wordpress');
        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
        $composerModulesDir = $this->getWordpressModulesDirectory($moduleType, 'composer');

        $availableModules = [
            'composer'  => [],
            'wordpress' => [],
        ];
        $updatedModules = [
            'new'       => [],
            'composer'  => [],
            'wordpress' => [],
        ];
        $visibleModules = [];
        $composerUpdateSuggested = false;
        // Collect registered Composer modules
        foreach ($this->getComposer()->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            if (strpos($package->getName(), $modulePrefix) === 0) {
                $availableModules['composer'][str_replace($modulePrefix, '', $package->getName())] = $package->getVersion();
            }
        }

        // Look for already available custom Wordpress modules
        if (is_dir($wpModulesDir)) {
            $dir = new \DirectoryIterator($wpModulesDir);
            /** @var \SplFileInfo $file */
            foreach ($dir as $file) {
                if ((!$file->isDir()) || strpos($file->getBasename(), '.') === 0) {
                    continue;
                }
                $availableModules['wordpress'][$file->getBasename()] = null;
            }
        }

        $conflictingNames = array_intersect_key($availableModules['composer'], $availableModules['wordpress']);
        if (count($conflictingNames)) {
            foreach ($conflictingNames as $module => $version) {
                $io->writeError(sprintf('<info>Wordpress %s <comment>%s</comment> is available both as Composer package and custom Wordpress %s. <error>You need to resolve conflict</error></info>', $typeName, $module, $typeName));
            }
            return false;
        }

        // Get list of modules inside Wordpress modules directory
        if (is_dir($modulesDir)) {
            $dir = new \DirectoryIterator($modulesDir);
            /** @var \SplFileInfo $file */
            foreach ($dir as $file) {
                if ((!$file->isDir()) || strpos($file->getBasename(), '.') === 0 || $this->isSymlink($file->getPathname())) {
                    continue;
                }
                $module = $file->getBasename();
                foreach ($availableModules as $type => $modules) {
                    if (array_key_exists($module, $modules)) {
                        $updatedModules[$type][$module] = null;
                        $module = null;
                        break;
                    }
                }
                if ($module !== null) {
                    $updatedModules['new'][$module] = null;
                }
            }
        }
        // Get list of modules that are visible for Wordpress along with their versions
        if (!$isFreshInstall) {
            if (!$this->runWpCliCommand(self::$wordpressModules[$moduleType]['wpcli_command'], 'list', [], $output, $error)) {
                $io->writeError(sprintf('<error>Failed to get list of installed Wordpress %ss</error>', $typeName));
            }
            $indexes = [
                'name'    => null,
                'version' => null,
            ];
            foreach ($output as $line) {
                if (strpos($line, '+-') === 0) {
                    continue;
                }
                $parts = (strpos($line, '|') !== false) ? explode('|', $line) : preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
                array_walk($parts, function (&$v) {
                    $v = trim($v);
                });
                if ($indexes['name'] === null) {
                    foreach ($indexes as $key => &$index) {
                        $index = array_search($key, $parts, true);
                    }
                    unset($index);
                    foreach ($indexes as $key => $i) {
                        if ($i === false) {
                            $io->writeError(sprintf('<error>Possibly unsupported output of WP CLI "%s list" command, unable to find "%s" column position</error>', $moduleType, $key));
                            return false;
                        }
                    }
                    continue;
                }
                $visibleModules[$parts[$indexes['name']]] = array_key_exists($indexes['version'], $parts) ? $parts[$indexes['version']] : null;
            }
        }

        // If there is some new Wordpress modules - check if they can be handled by Composer and update them accordingly
        $composer = $this->getComposer();
        $repositories = $composer->getRepositoryManager()->getRepositories();
        $repositories[] = $composer->getRepositoryManager()->getLocalRepository();
        /** @noinspection ReferenceMismatchInspection */
        if (array_key_exists('repositories', $composerConfig)) {
            foreach ($composerConfig['repositories'] as $repository) {
                $repositories[] = RepositoryFactory::createRepo($io, Factory::createConfig($io), $repository);
            }
        }
        $repositories = new CompositeRepository($repositories);
        foreach ($updatedModules['new'] as $module => $version) {
            if (!$isFreshInstall && !array_key_exists($module, $visibleModules)) {
                $io->writeError(sprintf('<info>Wordpress %s <comment>%s</comment> is found in filesystem but not known by Wordpress. Treating as custom Wordpress %s</info>', $typeName, $module, $typeName));
                $updatedModules['wordpress'][$module] = $version;
                continue;
            }
            if (!$isFreshInstall && $version === null) {
                $version = $visibleModules[$module];
            }
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            $package = $repositories->findPackage($modulePrefix . $module, new Constraint('>=', $version));
            if ($package instanceof PackageInterface && (string)$package->getPrettyVersion() !== '0') {
                // This package is available through Composer, update Composer configuration
                /** @noinspection ReferenceMismatchInspection */
                if (!array_key_exists('require', $composerConfig)) {
                    $composerConfig['require'] = [];
                }
                if (!array_key_exists($package->getName(), $composerConfig['require'])) {
                    $composerConfig['require'][$package->getName()] = $this->buildVersionConstraint($package->getVersion());
                    $io->write(sprintf('<info>Wordpress %s <comment>%s</comment>' . ($version !== null ? ' version <comment>%s</comment>' : '%s') . ' is converted into Composer package <comment>%s</comment> with <comment>%s</comment> version constraint</info>', $typeName, $module, $version, $package->getName(), $composerConfig['require'][$package->getName()]));
                    $composerUpdateSuggested = true;
                }
                /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                $fs->removeDirectory($modulesDir . '/' . $module);
            } else {
                // This package is not available through Composer, treat it as custom Wordpress package
                $io->write(sprintf('<info>Wordpress %s <comment>%s</comment>' . ($version !== null ? ' version <comment>%s</comment>' : '%s') . ' is not available as Composer package, storing as custom %s</info>', $typeName, $module, $version, $typeName));
                $updatedModules['wordpress'][$module] = $version;
            }
        }

        foreach ($updatedModules['composer'] as $module => $version) {
            if (!$isFreshInstall && !array_key_exists($module, $visibleModules) && !in_array($module, array_key_exists($moduleType, $this->newPackages) ? $this->newPackages[$moduleType] : [], true)) {
                // Module is most likely removed by Wordpress, ask what need to be done in this case
                $packageId = $modulePrefix . $module;
                if ($io->isInteractive()) {
                    if ($io->askConfirmation(sprintf('<info>Wordpress %s <comment>%s</comment> is configured in Composer but not visible for Wordpress, maybe it is deleted from Wordpress itself. Remove it from Composer configuration?</info> [<comment>Y,n</comment>]: ', $typeName, $module), true)) {
                        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                        $fs->removeDirectory($composerModulesDir . '/' . $module);
                        unset($composerConfig['require'][$packageId]);
                    }
                } else {
                    $io->write(sprintf('<info>Wordpress %s <comment>%s</comment> is configured in Composer but not visible for Wordpress, maybe it is deleted from Wordpress itself. Review your Composer configuration, you may want to remove <comment>%s</comment> package in "require" section</info>', $typeName, $module, $packageId));
                }
            }
        }

        foreach ($updatedModules['wordpress'] as $module => $version) {
            $current = $wpModulesDir . '/' . $module;
            $new = $modulesDir . '/' . $module;
            if (is_dir($new)) {
                if (is_dir($current)) {
                    $fs->remove($current);
                    $fs->rename($new, $current);
                    $io->write(sprintf('<info>Wordpress %s <comment>%s</comment> is updated from local copy installed by Wordpress</info>', $typeName, $module));
                } else {
                    $fs->rename($new, $current);
                    $io->write(sprintf('<info>Wordpress %s <comment>%s</comment> is stored as new custom %s</info>', $typeName, $module, $typeName));
                }
            }
        }

        foreach ($availableModules as $type => $modules) {
            foreach ($modules as $module => $version) {
                if (!$isFreshInstall) {
                    if (!array_key_exists($module, $visibleModules)) {
                        switch ($type) {
                            case 'composer':
                                if (!in_array($module, array_key_exists($moduleType, $this->newPackages) ? $this->newPackages[$moduleType] : [], true)) {
                                    $packageId = $modulePrefix . $module;
                                    if ($io->isInteractive()) {
                                        if ($io->askConfirmation(sprintf('<info>Wordpress %s <comment>%s</comment> is configured in Composer but not visible for Wordpress, maybe it is deleted from Wordpress itself. Remove it from Composer configuration?</info> [<comment>Y,n</comment>]: ', $typeName, $module), true)) {
                                            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                                            $fs->removeDirectory($composerModulesDir . '/' . $module);
                                            unset($composerConfig['require'][$packageId]);
                                        }
                                    } else {
                                        $io->write(sprintf('<info>Wordpress %s <comment>%s</comment> is configured in Composer but not visible for Wordpress, maybe it is deleted from Wordpress itself. Review your Composer configuration, you may want to remove <comment>%s</comment> package in "require" section</info>', $typeName, $module, $packageId));
                                    }
                                }
                                break;
                            case 'wordpress':
                                if ($io->isInteractive()) {
                                    if ($io->askConfirmation(sprintf('<info>Custom Wordpress %s <comment>%s</comment> is available in project but not visible for Wordpress, maybe it is deleted from Wordpress itself. Remove it from project?</info> [<comment>Y,n</comment>]: ', $typeName, $module), true)) {
                                        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                                        $fs->removeDirectory($wpModulesDir . '/' . $module);
                                    }
                                } else {
                                    $io->write(sprintf('<info>Custom Wordpress %s <comment>%s</comment> is available in project but not visible for Wordpress, maybe it is deleted from Wordpress itself. You may want to remove it</info>', $typeName, $module));
                                }
                                break;
                        }
                    }
                } else {
                    if ($type === 'composer') {
                        /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
                        $fs->removeDirectory($modulesDir . '/' . $module);
                    }
                }
            }
        }

        if ($composerUpdateSuggested) {
            $io->write(sprintf('<info>Some Wordpress %ss are converted into Composer packages, consider running <comment>composer update</comment> to install them</info>', $typeName));
        }

        // Build new directory with symlinks to Wordpress modules 
        $fs->ensureDirectoryExists($modulesDir);
        $dir = new \DirectoryIterator($modulesDir);
        /** @var \SplFileInfo $file */
        foreach ($dir as $file) {
            if (strpos($file->getBasename(), '.') === 0) {
                continue;
            } elseif ($file->isDir() || $this->isSymlink($file->getPathname())) {
                $fs->remove($file->getPathname());
            }
        }
        $this->createModulesLinks($wpModulesDir, $modulesDir);
        $this->createModulesLinks($composerModulesDir, $modulesDir);
        return true;
    }

    /**
     * Create symlinks from given source directory into target Wordpress modules directory
     *
     * @param string $srcDir
     * @param string $modulesDir
     */
    private function createModulesLinks($srcDir, $modulesDir)
    {
        if (!is_dir($srcDir)) {
            return;
        }
        $fs = $this->getFilesystem();
        $dir = new \DirectoryIterator($srcDir);
        /** @var \SplFileInfo $file */
        foreach ($dir as $file) {
            if ((!$file->isDir()) || strpos($file->getBasename(), '.') === 0 || $this->isSymlink($file->getPathname())) {
                continue;
            }
            $target = $modulesDir . '/' . $file->getBasename();
            if (file_exists($target)) {
                $fs->remove($target);
            }
            $this->symlink($target, $file->getPathname());
        }
    }

    /**
     * Build version constraint for Composer package by given actual package version
     *
     * @param string $version
     * @return string|false
     */
    private function buildVersionConstraint($version)
    {
        $parser = new VersionParser();
        try {
            $v = $parser->normalize($version);
            $stability = VersionParser::parseStability($v);
            if ($v === '9999999-dev') {
                $v = 'dev-trunk';
            } else {
                $v = explode('-', $v);
                $v = array_shift($v);
                $v = explode('.', $v);
                do {
                    $count = count($v);
                    if ($count <= 2) {
                        break;
                    }
                    if ((int)$v[$count - 1] === 0) {
                        array_pop($v);
                    } else {
                        break;
                    }
                } while (true);
                if ($stability === 'stable' && count($v) > 2) {
                    array_pop($v);
                }
                $v = '^' . implode('.', $v);
            }
            if ($stability !== 'stable') {
                $v .= '@' . $stability;
            }
            return $v;
        } catch (\UnexpectedValueException $e) {
            return false;
        }
    }

    /**
     * Get path to Wordpress console or false if it is not available
     *
     * @return string|false
     */
    private function getWordpressConsole()
    {
        if ($this->wordpressConsole === null) {
            $this->wordpressConsole = false;
            try {
                $binDir = $this->getComposer()->getConfig()->get('bin-dir');
                $filename = 'wp';
                if (Platform::isWindows()) {
                    $filename .= '.bat';
                }
                $consolePath = $binDir . '/' . $filename;
                if (file_exists($consolePath) && (Platform::isWindows() || is_executable($consolePath))) {
                    $this->wordpressConsole = $consolePath;
                }
            } catch (\Exception $e) {

            }
            if (!$this->wordpressConsole) {
                $this->getIO()->writeError('<error>WP CLI binary is not found</error>', true, IOInterface::DEBUG);
            }
        }
        return $this->wordpressConsole;
    }

    /**
     * Run given WP CLI command with given arguments
     *
     * @param string $section
     * @param string $command
     * @param array $args
     * @param array $output
     * @param array $error
     * @return boolean
     */
    private function runWpCliCommand($section, $command, array $args = [], &$output, &$error)
    {
        $output = [];
        $error = [];
        $cli = $this->getWordpressConsole();
        if (!$cli) {
            return false;
        }
        $io = $this->getIO();
        try {
            $cmd = [];
            /** @noinspection AdditionOperationOnArraysInspection */
            $parts = [
                    $cli,
                    $section,
                    $command,
                    'path'     => $this->getWordpressRootDirectory(),
                    'quiet'    => null,
                    'no-color' => null,
                ] + $args;
            foreach ($parts as $key => $arg) {
                if ($arg !== null) {
                    $arg = ProcessExecutor::escape($arg);
                }
                if (is_string($key)) {
                    $cmd[] = ((strpos($key, '-') === 0) ?: '--' . $key) . (($arg !== null) ? '=' . $arg : '');
                } elseif ($arg !== null) {
                    $cmd[] = $arg;
                }
            }
            $cmd = implode(' ', $cmd);
            $cmdout = '';
            $pe = $this->getProcessExecutor();
            $exitCode = $pe->execute($cmd, $cmdout, $this->getWordpressRootDirectory());
            $output = $pe->splitLines($cmdout);
            /** @noinspection ReferenceMismatchInspection */
            $io->write(implode("\n", $output), true, IOInterface::DEBUG);
            $error = $pe->splitLines($pe->getErrorOutput());
            $success = (int)$exitCode === 0;
            if (!$success) {
                /** @noinspection ReferenceMismatchInspection */
                $io->writeError(implode("\n", $error), true, IOInterface::DEBUG);
            }
            return $success;
        } catch (\Exception $e) {
            $io->writeError(sprintf('<error>WP CLI command "%s" is failed</error>', $command));
            $io->writeError(sprintf('<error>Exception: %s</error>', $e->getMessage()), true, IOInterface::DEBUG);
            return false;
        }
    }

    /**
     * Determine if Wordpress is installed
     *
     * @return boolean
     */
    private function isWordpressInstalled()
    {
        return $this->runWpCliCommand('core', 'is-installed', [], $output, $error);
    }

    /**
     * Determine if given path is symlink or not
     *
     * @param string $path
     * @return boolean
     */
    private function isSymlink($path)
    {
        if (!file_exists($path)) {
            return false;
        }
        if (Platform::isWindows()) {
            return $this->getFilesystem()->isJunction($path);
        }
        return is_link($path);
    }

    /**
     * Create platform-independent symlink to target directory from given source
     *
     * @param string $target
     * @param string $source
     */
    private function symlink($target, $source)
    {
        try {
            $fs = $this->getFilesystem();
            if (!$fs->isAbsolutePath($source)) {
                $source = $fs->normalizePath($source);
            }
            if (!file_exists($source)) {
                throw new \RuntimeException(sprintf('Symlink source path %s is not available', $source));
            }
            if (!$fs->isAbsolutePath($target)) {
                $target = $fs->normalizePath($target);
            }
            if (file_exists($target)) {
                throw new \RuntimeException(sprintf('Symlink target path %s is already available', $target));
            }
            if (Platform::isWindows()) {
                $fs->junction($source, $target);
            } else {
                if (!$fs->relativeSymlink($source, $target)) {
                    throw new \RuntimeException(error_get_last()['message']);
                }
            }
        } catch (\Exception $e) {
            $this->getIO()->writeError(sprintf('Failed to create symlink from "%s" to "%s"', $source, $target));
            $this->getIO()->writeError(sprintf('<error>%s</error>', $e->getMessage()), true, IOInterface::DEBUG);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'post-create-project-cmd' => 'onCreateProject',
            'post-install-cmd'        => 'onPostInstall',
            'post-update-cmd'         => 'onPostUpdate',
            'pre-package-install'     => 'onPrePackageInstall',
            'pre-package-uninstall'   => 'onPrePackageUninstall',
        ];
    }
}
