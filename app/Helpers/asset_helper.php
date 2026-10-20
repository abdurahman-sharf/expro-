<?php

/**
 * Builds the URL of a file under public/assets with its modification time as cache buster.
 *
 * @param string $path Path relative to public/assets.
 * @return string
 */
function asset_url(string $path): string
{
    $url = base_url('assets/' . $path);
    $file = FCPATH . 'assets/' . $path;

    return is_file($file) ? $url . '?v=' . filemtime($file) : $url;
}
