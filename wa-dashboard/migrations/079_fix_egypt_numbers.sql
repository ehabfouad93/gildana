-- Numbers saved before the phone fix: an Egyptian mobile written 01xxxxxxxxx with no country
-- chosen lost its 0 and was stored as 1xxxxxxxxx — which reads as a +1 number, but a +1 number
-- can never start 0 or 1 after the code. So every stored 10-digit 1[0125]… is such a mobile:
-- give it back its +20. IGNORE: where the same person is already there with +20, the old row is
-- left as it was (nothing is merged by itself).
UPDATE IGNORE contacts SET phone_e164 = CONCAT('20', phone_e164) WHERE phone_e164 REGEXP '^1[0125][0-9]{8}$';
