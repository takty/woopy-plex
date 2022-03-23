/**
 * Gulp file
 *
 * @author Takuto Yanagida
 * @version 2022-03-23
 */

/* eslint-disable no-undef */
'use strict';

const SRC_PHP  = ['src/**/*.php'];
const SRC_PO   = ['src/languages/**/*.po'];
const SRC_JSON = ['src/languages/**/*.json'];
const DEST     = './dist';

const gulp = require('gulp');

const { makeCopyTask }   = require('./task-copy');
const { makeLocaleTask } = require('./task-locale');


// -----------------------------------------------------------------------------


const php  = makeCopyTask(SRC_PHP, DEST);
const po   = makeLocaleTask(SRC_PO, DEST, 'src');
const json = makeCopyTask(SRC_JSON, DEST, 'src');

const watch = done => {
	gulp.watch(SRC_PHP, gulp.series(php));
	gulp.watch(SRC_PO, po);
	gulp.watch(SRC_JSON, json);
	done();
};

exports.build   = gulp.parallel(php, po, json);
exports.default = gulp.series(exports.build , watch);
