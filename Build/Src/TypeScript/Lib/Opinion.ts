import * as stream from "stream";

export class Opinion {
    constructor() {
        this.init();
    }

    init() {
        const button = document.getElementById('opinion-send');

        button.addEventListener('click', async e => {
            e.preventDefault();

            let _uri = button.dataset.action;
            const data = await this.getBrowserData();
            const response = await fetch(_uri, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: data
            });
            const responseData = await response.text();
        });
    }

    async getBrowserData() {
        let data = {
            'time': new Date().toLocaleString(),
            'browser': {
                'appCodeName': window.navigator.appCodeName,
                'appName': window.navigator.appName,
                'appVersion': window.navigator.appVersion,
                'plattform': window.navigator.platform,
                'language': window.navigator.language,
                'userAgent': window.navigator.userAgent,
                'vendor': window.navigator.vendor,
            },
            'document': {
                'uri': document.location.href,
            },
            'display': {
                'breakpoint': this.getActiveBreakpoint(),
                'height': this.getWindowHeight(),
                'width': this.getWindowWidth()
            },
            'page': this.getSystemInformations(),
            'screenshot': await this.getScreenshot()
        }

        return JSON.stringify(data);
    }

    async getScreenshot() {
        const admPanel: HTMLElement = document.getElementsByClassName('typo3-adminPanel-content-header-close')[0] as HTMLElement;
        admPanel.click();

        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        const video = document.createElement("video");
        const container = document.getElementById('testfoo');

        video.autoplay = true;

        try {
            const captureStream = await navigator.mediaDevices.getDisplayMedia({
                video: true
            });

            video.srcObject = captureStream;

            const frame = await new Promise((resolve, reject) => {
                let hasTimeouted = false;

                const timeoutId = setTimeout(() => {
                    hasTimeouted = true;
                    reject('Cannot render video capture');
                }, 5000);
                requestAnimationFrame(() => renderVideoToCanvas.apply(this))

                function renderVideoToCanvas(this: Opinion) {
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight);
                        clearTimeout(timeoutId);
                        resolve(canvas.toDataURL("image/png"));
                    } else {
                        if (!hasTimeouted) {
                            requestAnimationFrame(() => renderVideoToCanvas.apply(this));
                        }
                    }
                }
            });
            captureStream.getTracks().forEach(track => track.stop());
            return frame;

        } catch (err) {
            console.error("Error: " + err);
        }
    }

    getSystemInformations() {
        let infos = document.querySelector<HTMLElement>('#opinion-system-informations');
        return JSON.parse(infos.textContent);
    }

    getActiveBreakpoint() {
        let breakpoint = '';
        Array.from(document.querySelectorAll<HTMLElement>('.breakpoint')).forEach(controlElement => {
            let style = window.getComputedStyle(controlElement);
            if (style.display === 'block') {
                breakpoint = controlElement.dataset.breakpoint;
            }
        });

        return breakpoint;
    }

    getWindowWidth() {
        return window.innerWidth;
    }

    getWindowHeight() {
        return window.innerHeight;
    }
}
