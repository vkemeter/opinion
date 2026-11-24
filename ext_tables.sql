# Columns come from the TCA; only the indexes are defined here, and the status
# default, which the TCA of a read-only input field does not generate
CREATE TABLE tx_opinion_feedback (
	status varchar(255) DEFAULT 'new' NOT NULL,
	KEY page_status (page_uid, status)
);

CREATE TABLE tx_opinion_message (
	KEY feedback (feedback)
);

CREATE TABLE tx_opinion_read (
	UNIQUE KEY feedback_user (feedback, be_user)
);
