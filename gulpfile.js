var gulp = require('gulp'),
  gutil       = require('gulp-util'),
  sass        = require('gulp-sass'),
  uglify      = require('gulp-uglify'),
  browserSync = require('browser-sync').create(),
  concat      = require('gulp-concat');

// `gulp.task()` defines task that can be run calling `gulp xyz` from the command line

gulp.task('browser-sync', function() {
    browserSync.init({
      proxy: "crockenhill.dev"
    });
});

// Task sass
gulp.task('scss', function () {
    return gulp.src('./resources/assets/stylesheets/**/*.scss')
        .pipe(sass({errLogToConsole: true}))
        .pipe(gulp.dest('public/stylesheets'))
        .pipe(browserSync.reload({stream:true}));
});

gulp.task('js', function () {
    return gulp.src('./resources/assets/javascript/*.js')
        .pipe(uglify())
        .pipe(concat('all.js'))
        .pipe(gulp.dest('./public/scripts/'))
        .pipe(browserSync.reload({stream:true}));
});

gulp.task('watch:scss', function() {
  return gulp.watch(['./resources/assets/stylesheets/cbc/*.scss','./resources/assets/stylesheets/*.scss'],
    gulp.series('scss'));
});

gulp.task('watch:js', function() {
  return gulp.watch('./resources/assets/javascript/*.js',
    gulp.series('js'));
});

gulp.task('watch:php', function() {
  return gulp.watch('./**/*.php').on('change', browserSync.reload);
});

gulp.task('watch', gulp.parallel('watch:scss', 'watch:js', 'watch:php'));

gulp.task('default',
  gulp.series(
    gulp.parallel('watch', 'browser-sync')
  )
);
//
// // The `default` task gets called when no task name is provided to Gulp
// gulp.task('default', gulp.series('browser-sync', function () {
//
//     // add browserSync.reload to the tasks array to make
//     // all browsers reload after tasks are complete.
//     gulp.watch('./**/*.php', gulp.parallel(browserSync.reload));
//     gulp.watch('./resources/assets/javascript/*.js', gulp.parallel('js', browserSync.reload));
//     gulp.watch(['./resources/assets/stylesheets/**/*.scss'], gulp.parallel('sass', browserSync.reload));
//     gulp.watch(['./resources/assets/stylesheets/cbc/*.scss'], gulp.parallel('sass', browserSync.reload));
// }));
