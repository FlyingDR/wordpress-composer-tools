<?php

namespace Flying\Composer\Plugin;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
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
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
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
            $this->root = dirname($this->getComposer()->getConfig()->get('vendor-dir'));
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

    public function onCreateProject()
    {
        $this->variables = [];
        if ($this->getComposer()->getPackage()->getPrettyName() === 'flying/wordpress-composer') {
            $this->configureWordpressDirectories();
        }
        $this->configureComposer();
        $this->createWordpressConfig();
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
                '# Wordpress itself and related directories',
            ];
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

            $composerJson->write($config);
            $io->write('<info>composer.json is successfully updated</info>');

            // Create .gitignore
            file_put_contents($this->getProjectRoot() . '/.gitignore', implode("\n", $gitIgnore));
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
            foreach ($keysAndSalts as $key) {
                $value = $this->generateSecureString(64, true);
                $this->variables[$key] = [
                    'type'  => 'constant',
                    'value' => $value,
                ];
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
                    }
                }

                // Site URL configuration
                if ($io->askConfirmation('<info>Do you want to configure site URL parameters?</info> [<comment>Y,n</comment>]: ', true)) {
                    $siteUrl = $io->askAndValidate('Enter URL of home page of this Wordpress site: ', function ($value) use ($io) {
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
                    });
                    $this->variables['WP_SITEURL'] = [
                        'type'  => 'constant',
                        'value' => $siteUrl,
                    ];
                    $p = parse_url($siteUrl);
                    $this->variables['WP_HOME'] = [
                        'type'  => 'constant',
                        'value' => $p['scheme'] . '://' . $p['host'] . (array_key_exists('port', $p) ? ':' . $p['port'] : ''),
                    ];
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
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'post-create-project-cmd' => 'onCreateProject',
        ];
    }
}
