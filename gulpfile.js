var gulp = require('gulp'),
  gutil       = require('gulp-util'),
  sass        = require('gulp-sass'),
  uglify      = require('gulp-uglify'),
  browserSync = require('browser-sync').create(),
  concat      = require('gulp-concat'),
  RevAll      = require('gulp-rev-all'),
  sourcemaps = require('gulp-sourcemaps');


// `gulp.task()` defines task that can be run calling `gulp xyz` from the command line

gulp.task('browser-sync', function() {
    browserSync.init({
      proxy: "crockenhill.dev"
    });
});

// Task sass
gulp.task('scss', function () {
    return gulp.src('./resources/stylesheets/**/*.scss')
        .pipe(sourcemaps.init())
        .pipe(sass({errLogToConsole: true}))
        .pipe(sourcemaps.write('.'))
        .pipe(gulp.dest('public/stylesheets'))
        .pipe(browserSync.reload({stream:true}));
});

gulp.task('js', function () {
    return gulp.src('./resources/javascript/*.js')
        .pipe(sourcemaps.init())
        .pipe(uglify())
        .pipe(concat('all.js'))
        .pipe(sourcemaps.write('.'))
        .pipe(gulp.dest('./public/scripts/'))
        .pipe(browserSync.reload({stream:true}));
});

gulp.task('watch:scss', function() {
  return gulp.watch(['./resources/stylesheets/cbc/*.scss','./resources/stylesheets/*.scss'],
    gulp.series('scss'));
});

gulp.task('watch:js', function() {
  return gulp.watch('./resources/javascript/*.js',
    gulp.series('js'));
});

gulp.task('watch:php', function() {
  return gulp.watch(['./app/**/*.php', './bootstrap/**/*.php', './database/**/*.php', './resources/**/*.php', './routes/**/*.php', './storage/**/*.php', './tests/**/*.php']).on('change', browserSync.reload);
});

gulp.task('watch', gulp.parallel('watch:scss', 'watch:js', 'watch:php'));

gulp.task('default',
  gulp.series(
    gulp.parallel('watch', 'browser-sync')
  )
);
