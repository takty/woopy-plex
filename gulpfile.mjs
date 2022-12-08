/**
 * Gulp file
 *
 * @author Takuto Yanagida
 * @version 2022-12-08
 */

const SRC_PHP  = ['src/**/*.php'];
const SRC_PO   = ['src/languages/**/*.po'];
const SRC_JSON = ['src/languages/**/*.json'];
const DEST     = './dist';

import gulp from 'gulp';

import { makeCopyTask } from './gulp/task-copy.mjs';
import { makeLocaleTask }  from './gulp/task-locale.mjs';

const php  = makeCopyTask(SRC_PHP, DEST);
const po   = makeLocaleTask(SRC_PO, DEST, 'src');
const json = makeCopyTask(SRC_JSON, DEST, 'src');

const watch = done => {
	gulp.watch(SRC_PHP, gulp.series(php));
	gulp.watch(SRC_PO, po);
	gulp.watch(SRC_JSON, json);
	done();
};

export const build = gulp.parallel(php, po, json);
export default gulp.series(build , watch);
