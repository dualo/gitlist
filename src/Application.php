<?php

namespace GitList;

use Silex\Application as SilexApplication;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use GitList\Provider\GitServiceProvider;
use GitList\Provider\RepositoryUtilServiceProvider;
use GitList\Provider\ViewUtilServiceProvider;
use GitList\Provider\RoutingUtilServiceProvider;
use Symfony\Component\Filesystem\Filesystem;

/**
 * GitList application.
 */
class Application extends SilexApplication
{
    protected $path;

    /**
     * Constructor initialize services.
     *
     * @param Config $config
     * @param string $root   Base path of the application files (views, cache)
     */
    public function __construct(Config $config, $root = null)
    {
        parent::__construct();
        $app = $this;
        $this->path = realpath($root);

        $this['debug'] = $config->get('app', 'debug');
        $this['date.format'] = $config->get('date', 'format') ? $config->get('date', 'format') : 'd/m/Y H:i:s';

        $this['theme'] = 'default';

        $this['title'] = $config->get('app', 'title') ? $config->get('app', 'title') : 'GitList';
        $this['filetypes'] = $config->getSection('filetypes');
        $this['binary_filetypes'] = $config->getSection('binary_filetypes');
        $this['cache.archives'] = $this->getCachePath() . 'archives';
        $this['avatar.url'] = $config->get('avatar', 'url');
        $this['avatar.query'] = $config->get('avatar', 'query');
        $this['show_http_remote'] = $config->get('clone_button', 'show_http_remote');
        $this['use_https'] = $config->get('clone_button', 'use_https');
        $this['url_subdir'] = $config->get('clone_button', 'url_subdir');
        $this['http_user'] = $config->get('clone_button', 'http_user_dynamic') ? $_SERVER['PHP_AUTH_USER'] : $config->get('clone_button', 'http_user');
        $this['show_ssh_remote'] = $config->get('clone_button', 'show_ssh_remote');
        $this['ssh_user'] = $config->get('clone_button', 'ssh_user');

        // Register services
        $this->register(new TwigServiceProvider(), array(
            'twig.path'       => array($this->getThemePath($this['theme'])),
            'twig.options'    => $config->get('app', 'cache') ?
                                 array('cache' => $this->getCachePath() . 'views') : array(),
        ));

        $repositories = $config->get('git', 'repositories');
        $this['git.projects'] = $config->get('git', 'project_list') ?
                                $this->parseProjectList($config->get('git', 'project_list')) :
                                false;

        $this->register(new GitServiceProvider(), array(
            'git.client'         => $config->get('git', 'client'),
            'git.repos'          => $repositories,
            'ini.file'           => "config.ini",
            'git.hidden'         => $config->get('git', 'hidden') ?
                                    $config->get('git', 'hidden') : array(),
            'git.default_branch' => $config->get('git', 'default_branch') ?
                                    $config->get('git', 'default_branch') : 'master',
        ));

        $this->register(new ViewUtilServiceProvider());
        $this->register(new RepositoryUtilServiceProvider());
        $this->register(new RoutingUtilServiceProvider());

        $this['twig'] =  $this->extend('twig', function ($twig, $app) {
            $twig->addFilter(new \Twig_SimpleFilter('htmlentities', 'htmlentities'));
            $twig->addFilter(new \Twig_SimpleFilter('md5', 'md5'));
            $twig->addFilter(new \Twig_SimpleFilter('format_date', array($app, 'formatDate')));
            $twig->addFilter(new \Twig_SimpleFilter('format_size', array($app, 'formatSize')));
            $twig->addFunction(new \Twig_SimpleFunction('avatar', array($app, 'getAvatar')));

            $twig->addGlobal('theme', !isset($_COOKIE['gitlist-bootstrap-theme']) ? 'bootstrap-cosmo' : $_COOKIE['gitlist-bootstrap-theme']);

            $string = file_get_contents("./package.json");
            $pkg = json_decode($string, true);

            $twig->addGlobal('version', $pkg['version']);

            return $twig;
        });

        $this['escaper.argument'] = $this->factory(function() {
            return new Escaper\ArgumentEscaper();
        });

        // Handle errors
        $this->error(function (\Exception $e, $code) use ($app) {
            if ($app['debug']) {
                return;
            }

            return $app['twig']->render('error.twig', array(
                'message' => $e->getMessage(),
            ));
        });

        $this->finish(function () use ($app, $config) {
            if (!$config->get('app', 'cache')) {
                $fs = new Filesystem();
                $fs->remove($app['cache.archives']);
            }
        });
    }

    public function formatDate($date)
    {
        return $date->format($this['date.format']);
    }

    public function formatSize($size)
    {
        $mod = 1000;
        $units = array('B', 'kB', 'MB', 'GB');
        for($i = 0; $size > $mod; $i++) $size /= $mod;
        return round($size, 2) . $units[$i];
    }

    public function getAvatar($email, $size)
    {
        $url = $this['avatar.url'] ? $this['avatar.url'] : "//gravatar.com/avatar/";
        $query = array("s=$size");
        if (is_string($this['avatar.query']))
            $query[] = $this['avatar.query'];
        else if (is_array($this['avatar.query']))
            $query = array_merge($query, $this['avatar.query']);
        $id = md5(strtolower($email));
        return $url . $id . "?" . implode('&', $query);
    }

    public function getPath()
    {
        return $this->path . DIRECTORY_SEPARATOR;
    }

    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    public function getCachePath()
    {
        return $this->path
            . DIRECTORY_SEPARATOR
            . 'cache'
            . DIRECTORY_SEPARATOR;
    }

    public function getThemePath($theme)
    {
        return $this->path
            . DIRECTORY_SEPARATOR
            . 'themes'
            . DIRECTORY_SEPARATOR
            . $theme
            . DIRECTORY_SEPARATOR
            . 'twig'
            . DIRECTORY_SEPARATOR;
    }

    public function parseProjectList($project_list)
    {
        $projects = array();
        $file = fopen($project_list, "r");
        while ($file && !feof($file))
            $projects[] = trim(fgets($file));
        fclose($file);
        return $projects;
    }
}
