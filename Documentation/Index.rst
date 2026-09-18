.. SPDX-License-Identifier: GPL-2.0-or-later

==================
Prelumen Analytics
==================

TYPO3 12.4/13.4 Composer integration with site-scoped hosted account onboarding,
owner approval, encrypted recovery, live-entitlement dashboards and hosted billing.
See README.md for the complete installation, migration, scheduled refresh,
consent, cache and backend deployment prerequisites.

Public settings remain in Site Configuration under ``prelumenAnalytics``.
Credentials and recovery keys are encrypted in ``tx_onecoanalyticspro_state``.
``footerBadgeEnabled`` defaults to false. Metadata and commerce uploads are
not implemented. Existing manual connections remain available until explicitly
switching modes. A stable Core 1.0 release is required for public distribution.
