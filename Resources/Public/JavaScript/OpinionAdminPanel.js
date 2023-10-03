
window.addEventListener("DOMContentLoaded", () => {
    const button = document.getElementById('opinion-send');

    let running = false;

    button.addEventListener('click', async e => {
        e.preventDefault();

        await populateData();
    });

    const populateData = async () => {
        const _data = await getBrowserData();
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

        const messageElement = document.getElementById('opinion-textarea');

        if (messageElement) {
            messageElement.parentNode.removeChild(messageElement);
        }

        const send = document.getElementById('opinion-send');
        const submit = document.createElement('button');
        submit.id = 'opinion-submit';
        submit.textContent = 'Abschicken';
        submit.setAttribute('data-action', send.dataset.action);
        submit.className = 'typo3-adminPanel-btn typo3-adminPanel-btn-default';

        send.parentElement.appendChild(submit);
        send.remove();

        submit.addEventListener('click', async e => {
            e.preventDefault();
            let newData = document.querySelector('#opinion-dataset');

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

    const getBrowserData = async () => {
        return {
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
                'breakpoint': getActiveBreakpoint(),
                'height': getWindowHeight(),
                'width': getWindowWidth()
            },
            'page': getSystemInformations(),
            'screenshot': await getScreenshot(),
            'message': getMessage()
        }
    }

    const getActiveBreakpoint = () => {
        let breakpoint = '';
        Array.from(document.querySelectorAll('.breakpoint')).forEach(controlElement => {
            let style = window.getComputedStyle(controlElement);
            if (style.display === 'block') {
                breakpoint = controlElement.dataset.breakpoint;
            }
        });

        return breakpoint;
    };

    const getWindowWidth = () => {
        return window.innerWidth;
    }

    const getWindowHeight = () => {
        return window.innerHeight;
    }

    const getMessage = () => {
        const textarea = document.getElementById('opinion-message');

        if (textarea) {
            return textarea.value;
        }
    }

    const getScreenshot = async () => {
        if (running) {
            return;
        }

        running = true;

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

            if (admPanel) {
                document.querySelectorAll('.typo3-adminPanel-module-trigger').forEach((el) => {
                    if (el.textContent.trim() === 'Opinion') {
                        el.click();
                    }
                })
            }

            return frame;

        } catch (err) {
            console.error('The Image was not attached due to Users interaction.', err);
            return '';
        }
    }

    const getSystemInformations = () => {
        let infos = document.querySelector('#opinion-system-informations');

        if (infos) {
            return JSON.parse(infos.textContent);
        }
    }
});
