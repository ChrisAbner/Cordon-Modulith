<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Illuminate\Container\Container;

/**
 * A bare container that answers the path questions the framework helpers
 * (base_path, app_path, config_path...) ask the application, resolved against
 * a project root. It boots nothing and loads none of the project's code, so
 * config files that call those helpers can be evaluated outside Laravel.
 */
final class ProjectContainer extends Container
{
    public function __construct(private readonly string $basePath) {}

    public function basePath(string $path = ''): string
    {
        return $this->join($this->basePath, $path);
    }

    public function path(string $path = ''): string
    {
        return $this->join($this->basePath('app'), $path);
    }

    public function bootstrapPath(string $path = ''): string
    {
        return $this->join($this->basePath('bootstrap'), $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->join($this->basePath('config'), $path);
    }

    public function databasePath(string $path = ''): string
    {
        return $this->join($this->basePath('database'), $path);
    }

    public function langPath(string $path = ''): string
    {
        return $this->join($this->basePath('lang'), $path);
    }

    public function publicPath(string $path = ''): string
    {
        return $this->join($this->basePath('public'), $path);
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->join($this->basePath('resources'), $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->join($this->basePath('storage'), $path);
    }

    private function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($path, '/\\');
    }
}
