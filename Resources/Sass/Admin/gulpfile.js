'use strict';

const gulp       = require('gulp');
const sass       = require('gulp-sass')(require('sass'));
const rename     = require('gulp-rename');

const SRC  = './panel.scss';
const DEST = '../../Public/styles/';
const WATCH_GLOB = './**/*.scss';

function buildCss() {
  return gulp
    .src(SRC)
    .pipe(sass({ outputStyle: 'compressed', silenceDeprecations: ['legacy-js-api'] })
      .on('error', sass.logError))
    .pipe(rename('admin.css'))
    .pipe(gulp.dest(DEST));
}

function watchScss() {
  gulp.watch(WATCH_GLOB, buildCss);
}

exports.build   = buildCss;
exports.watch   = gulp.series(buildCss, watchScss);
exports.default = buildCss;
