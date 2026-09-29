-- The reports each person starred, shown first in the reports library.
ALTER TABLE users ADD COLUMN report_favs TEXT NULL AFTER crm_columns;
