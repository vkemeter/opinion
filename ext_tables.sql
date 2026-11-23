# Columns come from the TCA; only the indexes are defined here
CREATE TABLE tx_opinion_feedback (
	KEY page_status (page_uid, status)
);

CREATE TABLE tx_opinion_message (
	KEY feedback (feedback)
);

CREATE TABLE tx_opinion_read (
	UNIQUE KEY feedback_user (feedback, be_user)
);
