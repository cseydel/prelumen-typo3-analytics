-- SPDX-License-Identifier: GPL-2.0-or-later
CREATE TABLE tx_onecoanalyticspro_state (
    site_hash varchar(64) DEFAULT '' NOT NULL,
    payload mediumtext,
    PRIMARY KEY (site_hash)
);
