
import DocumentService from '@typo3/core/document-service.js';
import { Opinion } from "@vkemeter/opinion/Opinion.js";
import Notification from "@typo3/backend/notification.js";

DocumentService.ready().then(() => {
    document.getElementById('opinion-send').setAttribute('data-action', TYPO3.settings.ajaxUrls['opinion-backend']);

    const op = new Opinion();

    op.onsent = (msg) => {
        if (msg.success) {
            Notification.success(msg.title, msg.body);
        } else {
            Notification.error(msg.title, msg.body);
        }
    };
});
