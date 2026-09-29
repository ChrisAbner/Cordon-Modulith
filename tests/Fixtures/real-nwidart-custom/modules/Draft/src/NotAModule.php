<?php

namespace Acme\Draft;

use Acme\Blog\Posts\Post;

final class NotAModule
{
    public function post(Post $post): Post
    {
        return $post;
    }
}
