'use strict';

module.exports = function () {
    let build = __dirname.substring(0, __dirname.indexOf(__dirname.split('/').splice(-1).pop())) + __dirname.split('/').splice(-1).pop(),
        theme = __dirname.substring(0, __dirname.indexOf('opinion')) + 'opinion',
        webRoot = __dirname.substring(0, __dirname.indexOf('app')) + 'app/public';

    return {
        theme: build,
        browserPrefix: 'last 4 versions',
        backend: {
            css: {
                enabled: false,
                src: build + '/Src/Scss/Backend/*.scss',
                dest: theme + '/Resources/Public/Css/Backend/'
            },
            javascript: {
                enabled: false,
                src: build + '/Src/JavaScript/Backend.js',
                dest: theme + '/Resources/Public/JavaScript'
            }
        },
        favIcon: {
            enabled: false,
            srcHtml: build + '/Src/Favicon/FavIcon.html',
            icons: [
                {
                    name: 'Homepage',
                    data: build +'/Src/Favicon/Homepage/faviconData.json',
                    master: build + '/Src/Favicon/Homepage/FaviconMaster.svg',
                    dest: webRoot +'/favicons/Homepage/',
                    destDir: theme + '/Resources/Private/Partials/Page/FavIcons/Homepage/',
                    iconsPath: '/favicons/homepage/'
                }
            ],
        },
        config: {
            sourceFile: build + '/config.yaml'
        },
        browserSync: {
            watch: [
                build + '/Resources/Public/Css/Styles.min.css',
                build + '/Resources/Public/JavaScript/Main.min.js'
            ]
        },
        icoMoon: {
            src: build + '/Build/Src/IcoMoon/fonts/*',
            dest: build  + '/Resources/Public/Webfonts/IcoMoon/',
            data: build + '/Build/Src/IcoMoon/selection.json',
            scssDest: build + '/Build/Src/Scss/Abstracts/'
        },
        frontend: {
            css: {
                enabled: true,
                src: build + '/Src/Scss/Styles.scss',
                dest: theme + '/Resources/Public/Css',
                watch: build + '/Src/Scss/**/*.scss'
            },
            typescript: {
                enabled: true,
                src: build + '/Src/TypeScript/',
                entries: [
                    build + '/Src/TypeScript/main.ts',
                ],
                dest: theme + '/Resources/Public/JavaScript/',
                file: 'Opinion.js',
                watch: build + '/Src/TypeScript/**/*.ts'
            },
            javascript: {
                enabled: false,
                src: build + '/Src/JavaScript/Main.js',
                dest: theme + '/Resources/Public/JavaScript',
                watch: build + '/Src/JavaScript/**/*.js',
                includeJquery: true,
                modules: [
                    build +'/node_modules/frontend-pipeline-main/Src/JavaScript/Plugins/plugin.Breakoints.js',
                ],
                ie11: build + '/Src/JavaScript/IE11.js',
                ie11Modules: []
            },
            fonts: {
                enabled: false,
                src: build + '/Src/Fonts/**/*',
                dest: theme + '/Resources/Public/Webfonts'
            },
            images: {
                enabled: false,
                src: build + '/Src/Images/**/*',
                dest: theme + '/Resources/Public/Images',
                /** you need to add gulp-image for this **/
                optimize: false,
                config: {
                    pngquant: true,
                    optipng: true,
                    zopflipng: true,
                    jpegRecompress: true,
                    mozjpeg: true,
                    gifsicle: true,
                    svgo: true,
                    concurrent: 10,
                    quiet: false
                }
            }
        },
        misc: {
            enabled: false
        },
        test: {
            backstop: {
                enabled: false,
                id: "My Test",
                report: [ "browser" ],
                engine: "puppeteer",
                engineOptions: {
                    "args": ["--no-sandbox"]
                },
                asyncCaptureLimit: 5,
                asyncCompareLimit: 50,
                debug: false,
                debugWindow: false,
                paths: {
                    "bitmaps_reference": build + "/Test/backstop/backstop_data/bitmaps_reference",
                    "bitmaps_test": build + "/Test/backstop/backstop_data/bitmaps_test",
                    "engine_scripts": build + "/Test/backstop/backstop_data/engine_scripts",
                    "html_report": build + "/Test/backstop/backstop_data/html_report",
                    "ci_report": build + "/Test/backstop/backstop_data/ci_report"
                },
                scripts: {
                    onBeforeScript: build + "/node_modules/frontend-pipeline-main/test/backstop/engine_scripts/puppet/onBefore.js",
                    onReadyScript: build + "/node_modules/frontend-pipeline-main/test/backstop/engine_scripts/puppet/onReady.js",
                }
            }
        },
        clean: {
            enabled: false,
            files: [
                theme + '/Resources/Public/JavaScript',
            ],
        }
    };
};
