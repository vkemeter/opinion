var _gulp = require('gulp'),
    _config = require("./config"),
    _glass = require('@agostone/gulp-glass'),
    _taskLoader = new _glass({
        taskPaths: 'node_modules/frontend-pipeline-main/tasks'
    });

_taskLoader.loadTasks();

/** an example default task */
_gulp.task('default',
    _gulp.series(
        _gulp.parallel(
            'Frontend:TYPESCRIPT',
            'Frontend:SCSS',
        )
    )
);

_gulp.task('watch',
    _gulp.series(
        'default',
        function() {
            _gulp.watch([ _config().frontend.typescript.watch ], {interval: 1000, usePolling: true}, _gulp.parallel('Frontend:TYPESCRIPT'));
            _gulp.watch([ _config().frontend.css.watch ], {interval: 1000, usePolling: true}, _gulp.parallel('Frontend:SCSS'));
        }
    )
);
