var _gulp = require('gulp'),
    _config = require("./config"),
    _glass = require('@agostone/gulp-glass'),
    _mjml = require('gulp-mjml'),
    _mjmlEngine = require('mjml'),
    _taskLoader = new _glass({
        taskPaths: 'node_modules/frontend-pipeline-main/tasks'
    });

_taskLoader.loadTasks();

/** mjml task for email templates */
_gulp.task('MJML:Templates', function () {
    return _gulp.src('./Src/Templates/**/*.mjml')
        .pipe(_mjml(_mjmlEngine, {
            beautify: true,
        }))
        .on('error', function(err){
            console.log(err.toString());
            this.emit('end');
        })
        .pipe(_gulp.dest('./Dist'))
});


/** an example default task */
_gulp.task('default',
    _gulp.series(
        _gulp.parallel(
            'Frontend:TYPESCRIPT',
            'Frontend:JS',
            'Frontend:SCSS',
        )
    )
);

_gulp.task('watch',
    _gulp.series(
        'default',
        function() {
            _gulp.watch([ _config().frontend.javascript.watch ], {interval: 1000, usePolling: true}, _gulp.parallel('Frontend:JS'));
            _gulp.watch([ _config().frontend.typescript.watch ], {interval: 1000, usePolling: true}, _gulp.parallel('Frontend:TYPESCRIPT'));
            _gulp.watch([ _config().frontend.css.watch ], {interval: 1000, usePolling: true}, _gulp.parallel('Frontend:SCSS'));
            _gulp.watch([ './Src/Templates/**/*.mjml' ], {interval: 1000, usePolling: true}, _gulp.parallel('MJML:Templates'));
        }
    )
);
