-- SMS: the platform admin lists the gateways a client may pick from; the client's own admin picks one.
ALTER TABLE clients ADD COLUMN sms_allowed_gateways VARCHAR(500) NULL;   -- platform gateway ids they may choose (comma list); their default is always allowed
ALTER TABLE clients ADD COLUMN sms_choice VARCHAR(20) NULL;              -- what their admin chose: a platform gateway id, or 'own'; NULL = the default
