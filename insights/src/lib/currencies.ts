/** Currencies offered as a reporting currency (ad accounts can be in any). */
export const CURRENCIES: { code: string; name: string }[] = [
  { code: "USD", name: "US dollar" }, { code: "EGP", name: "Egyptian pound" }, { code: "SAR", name: "Saudi riyal" },
  { code: "AED", name: "UAE dirham" }, { code: "KWD", name: "Kuwaiti dinar" }, { code: "QAR", name: "Qatari riyal" },
  { code: "BHD", name: "Bahraini dinar" }, { code: "OMR", name: "Omani rial" }, { code: "JOD", name: "Jordanian dinar" },
  { code: "LBP", name: "Lebanese pound" }, { code: "IQD", name: "Iraqi dinar" }, { code: "MAD", name: "Moroccan dirham" },
  { code: "TND", name: "Tunisian dinar" }, { code: "DZD", name: "Algerian dinar" }, { code: "LYD", name: "Libyan dinar" },
  { code: "EUR", name: "Euro" }, { code: "GBP", name: "British pound" }, { code: "TRY", name: "Turkish lira" },
  { code: "INR", name: "Indian rupee" }, { code: "PKR", name: "Pakistani rupee" }, { code: "CAD", name: "Canadian dollar" },
  { code: "AUD", name: "Australian dollar" }, { code: "CHF", name: "Swiss franc" }, { code: "JPY", name: "Japanese yen" },
  { code: "CNY", name: "Chinese yuan" },
];
export const CURRENCY_CODES = CURRENCIES.map((c) => c.code);
