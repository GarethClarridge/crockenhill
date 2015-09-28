var gulp = require('gulp'),
  gutil       = require('gulp-util'),
  sass        = require('gulp-sass'),
  uglify      = require('gulp-uglify'),
  browserSync = require('browser-sync'),
  concat      = require('gulp-concat');

// `gulp.task()` defines task that can be run calling `gulp xyz` from the command line

gulp.task('browser-sync', function() {
    browserSync({
        proxy: "localhost:8000"
    });
});

// Task sass
gulp.task('sass', function () {
    gulp.src('resources/assets/stylesheets/main.scss')
        .pipe(sass({errLogToConsole: true}))
        .pipe(gulp.dest('public/stylesheets'));
});

gulp.task('js', function () {
    gulp.src('./resources/assets/javascript/*.js')
        .pipe(uglify())
        .pipe(concat('all.js'))
        .pipe(gulp.dest('./public/scripts/'));
});

// The `default` task gets called when no task name is provided to Gulp
gulp.task('default', ['browser-sync'], function () {

    // add browserSync.reload to the tasks array to make
    // all browsers reload after tasks are complete.
    gulp.watch('./**/*.php', browserSync.reload);
    gulp.watch('./resources/assets/javascript/*.js', ['js', browserSync.reload]);
    gulp.watch(['./resources/assets/stylesheets/**/*.scss'], ['sass', browserSync.reload]);
    gulp.watch(['./resources/assets/stylesheets/cbc/*.scss'], ['sass', browserSync.reload]);
});