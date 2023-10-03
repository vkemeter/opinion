
export class Opinion {
    onsent = null

    constructor() {
        const button = document.getElementById('opinion-send');

        button.addEventListener('click', async e => {
            e.preventDefault();

            let data = await this.getBrowserData();

            if (button.classList.contains('opinion-be')) {
                const selectedPageTreeItem = document.getElementsByClassName('node-selected')[0];

                if (selectedPageTreeItem) {
                    data['page']['pageUid'] = selectedPageTreeItem.getAttribute('data-state-id');
                }

                let _uri = button.dataset.action;
                const response = await fetch(_uri, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data),
                });
                const resp = await response.json();

                if (this.onsent) {
                    this.onsent(resp);
                }
            } else {
                this.populateData(data);
            }
        });
    }

    toggleAdminPanel() {
        const admPanelTabs = Array.from(document.getElementsByClassName('typo3-adminPanel-module-trigger-label'));

        for (let tab of admPanelTabs) {
            if (tab.textContent === 'Opinion') {
                tab.parentElement.click();
            }
        }
    }

    populateData(data) {
        this.toggleAdminPanel();
        const _data = data;

        const _dataElem = document.getElementById('opinion-data');

        const _hidden = document.createElement('div');
        _hidden.className = 'display-none';
        _hidden.id = 'opinion-dataset';
        _hidden.innerText = JSON.stringify(_data);

        const _table = document.createElement('table');
        _table.className = "sf- opinion-data-table";

        for (let key in _data) {
            if (typeof _data[key] === "object") {
                const _row = document.createElement('tr');
                const _label = document.createElement('th');
                const _col = document.createElement('td');
                _label.textContent = key;

                const _list = document.createElement('ul');
                _list.className = 'sf-';
                for (let item in _data[key]) {
                    const _item = document.createElement('li');

                    if (item === 'imagesOnPage') {
                        const _subItemList = document.createElement('ul');
                        _subItemList.className = 'sf-';
                        _item.innerHTML = '<span>' + item + '</span>';

                        for (let _subItem in _data[key][item]) {
                            if (_subItem !== 'files') {
                                const _subItemListItem = document.createElement('li');
                                _subItemListItem.innerHTML = '<span>' + _subItem + ':</span> ' + _data[key][item][_subItem];
                                _subItemList.appendChild(_subItemListItem);
                            }
                        }

                        _item.appendChild(_subItemList);

                    } else {
                        _item.innerHTML = '<span>' + item + ':</span> ' + _data[key][item];
                    }
                    _list.appendChild(_item);
                }

                _col.appendChild(_list);
                _row.appendChild(_label);
                _row.appendChild(_col);
                _table.appendChild(_row);
            } else {
                if (_data[key] !== '') {
                    const _row = document.createElement('tr');
                    const _label = document.createElement('th');
                    const _col = document.createElement('td');
                    _label.innerHTML = key;

                    if (key === 'screenshot') {
                        const img = document.createElement("img");
                        img.src = _data[key];
                        img.className = 'opinion-image';
                        _col.appendChild(img);
                    } else {
                        _col.textContent = _data[key];
                    }

                    _row.appendChild(_label);
                    _row.appendChild(_col);
                    _table.appendChild(_row);
                }
            }
        }

        _dataElem.appendChild(_table);
        _dataElem.appendChild(_hidden);
        document.getElementById('opinion-textarea').remove();

        const send = document.getElementById('opinion-send');
        const submit = document.createElement('button');
        submit.id = 'opinion-submit';
        submit.textContent = 'Abschicken';
        submit.setAttribute('data-action', send.dataset.action);
        submit.className = 'btn btn-primary sf-reset';

        send.parentElement.appendChild(submit);
        send.remove();

        submit.addEventListener('click', async e => {
            e.preventDefault();
            let newData = document.querySelector<HTMLElement>('#opinion-dataset');

            if (newData) {
                let _uri = submit.dataset.action;
                const response = await fetch(_uri, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: newData.textContent
                });
                const responseData = await response.json();

                if (responseData) {
                    location.reload();
                }
            }
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
            'screenshot': await this.getScreenshot(),
            'message': this.getMessage()
        }

        return data;
    }

    async getScreenshot() {
        const admPanel = document.getElementsByClassName('typo3-adminPanel-content-header-close')[0];

        if (admPanel) {
            admPanel.click();
        }

        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        const video = document.createElement("video");

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
                requestAnimationFrame(() => renderVideoToCanvas())

                const renderVideoToCanvas = () => {
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        context.drawImage(video, 0, 0, video.videoWidth, video.videoHeight);
                        clearTimeout(timeoutId);
                        resolve(canvas.toDataURL("image/png"));
                    } else {
                        if (!hasTimeouted) {
                            requestAnimationFrame(() => renderVideoToCanvas());
                        }
                    }
                }
            });
            captureStream.getTracks().forEach(track => track.stop());
            return frame;

        } catch (err) {
            console.log('The Image was not attached due to Users interaction.');
            return '';
        }
    }

    getSystemInformations() {
        let infos = document.querySelector('#opinion-system-informations');

        if (infos) {
            return JSON.parse(infos.textContent);
        }
    }

    getActiveBreakpoint() {
        let breakpoint = '';
        Array.from(document.querySelectorAll('.breakpoint')).forEach(controlElement => {
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

    getMessage() {
        const textarea = document.getElementById('opinion-message');

        if (textarea) {
            return textarea.value;
        }
    }
}
