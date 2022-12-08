/**
 * Function for gulp (Locale)
 *
 * @author Takuto Yanagida
 * @version 2022-09-16
 */

import gulp from 'gulp';
import gp from 'gettext-parser';
import plumber from 'gulp-plumber';
import changed from 'gulp-changed';

export function makeLocaleTask(src, dest = '/dest', base = null) {
	const localeTask = () => gulp.src(src, { base: base })
		.pipe(plumber())
		.on('data', f => {
			f.path = f.path.replace(/\.po$/, '.mo');
			return f.contents = gp.mo.compile(gp.po.parse(f.contents));
		})
		.pipe(changed(dest, { hasChanged: changed.compareContents, extension: '.mo' }))
		.pipe(gulp.dest(dest));
	return localeTask;
}
