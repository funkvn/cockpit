<?php

// Helpers

$this->helpers['revisions']  = 'Cockpit\\Helper\\Revisions';
$this->helpers['updater']  = 'Cockpit\\Helper\\Updater';

// API
$this->module("cockpit")->extend([

    "markdown" => function($content, $extra = false) use($app) {

        static $parseDown;
        static $parsedownExtra;

        if (!$extra && !$parseDown)      $parseDown      = new \Parsedown();
        if ($extra && !$parsedownExtra)  $parsedownExtra = new \ParsedownExtra();

        return $extra ? $parsedownExtra->text($content) : $parseDown->text($content);
    },

    "clearCache" => function() use($app) {

        $dirs = ['#cache:','#tmp:','#thumbs:'];

        foreach($dirs as $dir) {

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app->path($dir)), \RecursiveIteratorIterator::SELF_FIRST);

            foreach ($files as $file) {

                if (!$file->isFile()) continue;
                if (preg_match('/(\.gitkeep|\.gitignore|index\.html)$/', $file)) continue;

                @unlink($file->getRealPath());
            }

            $app->helper("fs")->removeEmptySubFolders('#cache:');
        }

        $app->trigger("cockpit.clearcache");

        $size = 0;

        foreach($dirs as $dir) {
            $size += $app->helper("fs")->getDirSize($dir);
        }

        return ["size"=>$app->helper("utils")->formatSize($size)];
    },

    "loadApiKeys" => function() {

        $keys      = [ 'master' => '', 'special' => [] ];
        $container = $this->app->path('#storage:').'/api.keys.php';

        if (file_exists($container)) {
            $data = include($container);
            $data = unserialize($this->app->decode($data, $this->app["sec-key"]));

            if ($data !== false) {
                $keys = array_merge($keys, $data);
            }
        }

        return $keys;
    },

    "saveApiKeys" => function($data) {

        $data      = serialize(array_merge([ 'master' => '', 'special' => [] ], (array)$data));
        $export    = var_export($this->app->encode($data, $this->app["sec-key"]), true);
        $container = $this->app->path('#storage:').'/api.keys.php';

        return $this->app->helper('fs')->write($container, "<?php\n return {$export};");
    },

    "thumbnail" => function($options) {

        $options = array_merge(array(
            'cachefolder' => '#thumbs:',
            'src' => '',
            'mode' => 'thumbnail',
            'fp' => null,
            'filter' => '',
            'width' => false,
            'height' => false,
            'quality' => 100,
            'rebuild' => false,
            'base64' => false,
            'output' => false,
            'domain' => false
        ), $options);

        extract($options);

        if (!$width && !$height) {
            return ['error' => 'Target width or height parameter is missing'];
        }

        if (!$src) {
            return ['error' => 'Missing src parameter'];
        }

        $src = str_replace('../', '', rawurldecode($src));

        if (!preg_match('/\.(png|jpg|jpeg|gif)$/i', $src)) {

            if ($asset = $this->app->storage->findOne("cockpit/assets", ['_id' => $src])) {
                $asset['path'] = trim($asset['path'], '/');
                $src = $this->app->path("#uploads:{$asset['path']}");

                if ($src) {
                    $src = str_replace(COCKPIT_SITE_DIR, '', $src);
                }

                if (isset($asset['fp']) && !$fp) {
                    $fp = $asset['fp']['x'].' '.$asset['fp']['y'];
                }

            }
        }

        if ($src) {

            $src = ltrim($src, '/');

            if (file_exists(COCKPIT_SITE_DIR.'/'.$src)) {
                $src = COCKPIT_SITE_DIR.'/'.$src;
            } elseif (file_exists(COCKPIT_DOCS_ROOT.'/'.$src)) {
                $src = COCKPIT_DOCS_ROOT.'/'.$src;
            }
        }

        $path  = $this->app->path($src);
        $ext   = pathinfo($path, PATHINFO_EXTENSION);
        $url   = "data:image/gif;base64,R0lGODlhAQABAJEAAAAAAP///////wAAACH5BAEHAAIALAAAAAABAAEAAAICVAEAOw=="; // transparent 1px gif

        if (!file_exists($path) || is_dir($path)) {
            return false;
        }

        if (!in_array(strtolower($ext), array('png','jpg','jpeg','gif'))) {
            return $url;
        }

        if (!$width || !$height) {

            list($w, $h, $type, $attr)  = getimagesize($path);

            if (!$width) $width = ceil($w * ($height/$h));
            if (!$height) $height = ceil($h * ($width/$w));
        }

        if (is_null($width) && is_null($height)) {
            return $this->app->pathToUrl($path);
        }

        if (!$fp) {
            $fp = 'center';
        }

        if (!in_array($mode, ['thumbnail', 'bestFit', 'resize','fitToWidth','fitToHeight'])) {
            $mode = 'thumbnail';
        }

        $method = $mode == 'crop' ? 'thumbnail' : $mode;

        $filetime = filemtime($path);
        $hash = md5($path.json_encode($options))."_{$width}x{$height}_{$quality}_{$filetime}_{$mode}_".md5($fp).".{$ext}";
        $savepath = rtrim($this->app->path($cachefolder), '/')."/{$hash}";

        if ($rebuild || !file_exists($savepath)) {

            try {

                $img = $this->app->helper("image")->take($path)->{$method}($width, $height, $fp);

                $_filters = [
                    'blur', 'brighten',
                    'colorize', 'contrast',
                    'darken', 'desaturate',
                    'edge detect', 'emboss',
                    'flip', 'invert', 'opacity', 'pixelate', 'sepia', 'sharpen', 'sketch'
                ];

                foreach($_filters as $f) {

                    if (isset($options[$f])) {
                        $img->{$f}($options[$f]);
                    }
                }

                $img->toFile($savepath, null, $quality);
            } catch(Exception $e) {
                return $url;
            }
        }

        if ($base64) {
            return "data:image/{$ext};base64,".base64_encode(file_get_contents($savepath));
        }

        if ($output) {
            header("Content-Type: image/{$ext}");
            header('Content-Length: '.filesize($savepath));
            readfile($savepath);
            $this->app->stop();
        }

        $url = $this->app->pathToUrl($savepath);

        if ($domain) {

            $_url = ($this->app->req_is('ssl') ? 'https':'http').'://';

            if (!in_array($this->app['base_port'], ['80', '443'])) {
                $_url .= $this->app['base_host'].":".$this->app['base_port'];
            } else {
                $_url .= $this->app['base_host'];
            }

            $url = rtrim($_url, '/').$url;
        }

        return $url;
    }
]);


// Additional module Api
include_once(__DIR__.'/module/auth.php');
include_once(__DIR__.'/module/assets.php');

// REST
if (COCKPIT_API_REQUEST) {

    // INIT REST API HANDLER
    include_once(__DIR__.'/rest-api.php');

    $this->on('cockpit.rest.init', function($routes) {
        $routes['cockpit'] = 'Cockpit\\Controller\\RestApi';
    });
}

if (COCKPIT_ADMIN) {

    $this->bind("/api.js", function() {

        $token                = $this->param('token', '');
        $this->response->mime = 'js';

        $apiurl = ($this->req_is('ssl') ? 'https':'http').'://';

        if (!in_array($this->registry['base_port'], ['80', '443'])) {
            $apiurl .= $this->registry['base_host'].":".$this->registry['base_port'];
        } else {
            $apiurl .= $this->registry['base_host'];
        }

        $apiurl .= $this->routeUrl('/api');

        return $this->view('cockpit:views/api.js', compact('token', 'apiurl'));
    });
}


// ADMIN
if (COCKPIT_ADMIN && !COCKPIT_API_REQUEST) {
    include_once(__DIR__.'/admin.php');
}

// CLI
if (COCKPIT_CLI) {
    $this->path('#cli', __DIR__.'/cli');
}

// WEBHOOKS
if (!defined('COCKPIT_INSTALL')) {
    include_once(__DIR__.'/webhooks.php');
}
