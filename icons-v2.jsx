/* Gildana — line icons + small UI svgs */

const ArrowRight = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M2 7 L12 7" />
    <path d="M8 3 L12 7 L8 11" />
  </svg>
);
const ArrowLeft = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
    <path d="M12 7 L2 7" />
    <path d="M6 3 L2 7 L6 11" />
  </svg>
);

// 6 service icons (line style, target/pencil/megaphone/box/chart/code)
const IconStrategy = ({ size = 36 }) => (
  <svg width={size} height={size} viewBox="0 0 36 36" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <circle cx="18" cy="18" r="13" />
    <circle cx="18" cy="18" r="8" />
    <circle cx="18" cy="18" r="3" />
    <path d="M18 5 V2 M18 34 V31 M5 18 H2 M34 18 H31" />
  </svg>
);
const IconBranding = ({ size = 36 }) => (
  <svg width={size} height={size} viewBox="0 0 36 36" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M5 30 L9 26 L26 9 L30 13 L13 30 L5 30 Z" />
    <path d="M22 9 L26 13" />
    <path d="M5 30 L8 27" />
  </svg>
);
const IconMegaphone = ({ size = 36 }) => (
  <svg width={size} height={size} viewBox="0 0 36 36" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M5 14 L23 8 V28 L5 22 Z" />
    <path d="M5 14 V22" />
    <path d="M23 12 H28 A4 4 0 0 1 28 24 H23" />
    <path d="M9 22 L11 30" />
  </svg>
);
const IconBox = ({ size = 36 }) => (
  <svg width={size} height={size} viewBox="0 0 36 36" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M18 5 L31 11 V25 L18 31 L5 25 V11 Z" />
    <path d="M5 11 L18 17 L31 11" />
    <path d="M18 17 V31" />
    <path d="M11.5 8 L24.5 14" strokeDasharray="2 2" />
  </svg>
);
const IconChart = ({ size = 36 }) => (
  <svg width={size} height={size} viewBox="0 0 36 36" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M5 30 H31" />
    <rect x="8" y="20" width="4" height="10" />
    <rect x="16" y="14" width="4" height="16" />
    <rect x="24" y="8" width="4" height="22" />
  </svg>
);
const IconCode = ({ size = 36 }) => (
  <svg width={size} height={size} viewBox="0 0 36 36" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M12 11 L5 18 L12 25" />
    <path d="M24 11 L31 18 L24 25" />
    <path d="M21 7 L15 29" />
  </svg>
);

const IconPhone = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M3 2 H5 L6 5 L4.5 6 A6 6 0 0 0 8 9.5 L9 8 L12 9 V11 A1 1 0 0 1 11 12 A9 9 0 0 1 2 3 A1 1 0 0 1 3 2 Z" />
  </svg>
);
const IconMail = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <rect x="2" y="3" width="10" height="8" rx="1" />
    <path d="M2 4 L7 8 L12 4" />
  </svg>
);
const IconPin = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
    <path d="M7 12.5 C4 9 2.5 7 2.5 5 A4.5 4.5 0 0 1 11.5 5 C11.5 7 10 9 7 12.5 Z" />
    <circle cx="7" cy="5" r="1.5" />
  </svg>
);
const IconChat = ({ size = 22 }) => (
  <svg width={size} height={size} viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
    <path d="M3 16 L3 6 A2 2 0 0 1 5 4 H17 A2 2 0 0 1 19 6 V14 A2 2 0 0 1 17 16 H7 L3 19 Z" />
  </svg>
);

// Social
const SocialIG = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.3">
    <rect x="2" y="2" width="10" height="10" rx="3" />
    <circle cx="7" cy="7" r="2.4" />
    <circle cx="10.2" cy="3.8" r="0.5" fill="currentColor" />
  </svg>
);
const SocialFB = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="currentColor">
    <path d="M8.5 12.5 V8 H10.2 L10.5 6 H8.5 V4.8 C8.5 4.2 8.7 3.8 9.6 3.8 H10.6 V2 C10.4 2 9.7 1.9 9 1.9 C7.5 1.9 6.5 2.8 6.5 4.5 V6 H4.8 V8 H6.5 V12.5 H8.5 Z" />
  </svg>
);
const SocialBE = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.2">
    <path d="M1.5 4 H5 A1.5 1.5 0 0 1 5 7 H1.5 Z" />
    <path d="M1.5 7 H5.4 A1.7 1.7 0 0 1 5.4 10.4 H1.5 Z" />
    <path d="M8 6 H12.5" />
    <path d="M8.2 8 A2.2 2 0 1 0 12.3 8.5 H8.2 A2.2 2 0 1 0 10.3 11" />
  </svg>
);
const SocialLI = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="currentColor">
    <rect x="2" y="5" width="2" height="7" />
    <circle cx="3" cy="3" r="1.2" />
    <path d="M5.5 5 H7.5 V6 C8 5.4 8.8 5 9.7 5 C11.4 5 12 6.2 12 7.8 V12 H10 V8.3 C10 7.5 9.7 6.9 8.9 6.9 C8 6.9 7.5 7.5 7.5 8.4 V12 H5.5 Z" />
  </svg>
);

Object.assign(window, {
  ArrowRight, ArrowLeft,
  IconStrategy, IconBranding, IconMegaphone, IconBox, IconChart, IconCode,
  IconPhone, IconMail, IconPin, IconChat,
  SocialIG, SocialFB, SocialBE, SocialLI,
});
