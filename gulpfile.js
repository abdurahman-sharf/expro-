// Gulp Build Script: release archives and license reports. Frontend assets are built by tools/build-assets.mjs.

import gulp from 'gulp'
import tar from 'gulp-tar'
import zip from 'gulp-zip'
import run from 'gulp-run'

import { Stream } from 'readable-stream'
const {finished, pipeline} = Stream.promises

gulp.task('compress', function() {
    const sources = ['app*/**/*', 'public*/**/*', 'vendor*/**/*', '*.md', 'docs/**/*', 'LICENSE', 'docker*', 'Dockerfile', '**/.htaccess', 'writable*/**/*', '.env'] ;
    gulp.src(sources, {encoding: false}).pipe(tar('opensourcepos.tar')).pipe(gulp.dest('dist'));
    return gulp.src(sources, {encoding: false}).pipe(zip('opensourcepos.zip')).pipe(gulp.dest('dist'));
});

gulp.task('update-licenses', function() {
    function run_completion(execStream) {
        return finished(execStream.resume());
    }

    return Promise.all([
        run_completion(run('composer licenses --format=json --no-dev > public/license/composer.LICENSES').exec()),
        run_completion(run('npx license-report --only=prod --output=json --fields=name --fields=author --fields=homepage --fields=installedVersion --fields=licenseType > public/license/npm-prod.LICENSES').exec()),
        run_completion(run('npx license-report --only=dev --output=json --fields=name --fields=author --fields=homepage --fields=installedVersion --fields=licenseType > public/license/npm-dev.LICENSES').exec()),
        pipeline(gulp.src('LICENSE'),gulp.dest('public/license'))
    ]);
});

gulp.task('default', gulp.series('update-licenses'));
