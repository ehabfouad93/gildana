/* Gildana — Ornaments
   Subtle G-pattern motifs derived from the brand's four-G emblem + bloom.
   Pure SVG, no complex custom drawing beyond simple geometric primitives.
*/

// The "G" emblem — four G-shapes in a 2x2 grid, symmetrical.
// Built from circles + rectangles (primitives only).
const GEmblem = ({ size = 80, color = "#C4973A", strokeWidth = 1.2 }) => (
  <svg width={size} height={size} viewBox="0 0 80 80" fill="none" stroke={color} strokeWidth={strokeWidth}>
    {/* four quadrants of G, each a ring with an inward tab */}
    {/* top-left */}
    <g transform="translate(20,20)">
      <circle cx="0" cy="0" r="14" />
      <rect x="0" y="-1" width="8" height="2" fill={color} stroke="none" />
    </g>
    {/* top-right (mirrored) */}
    <g transform="translate(60,20) scale(-1,1)">
      <circle cx="0" cy="0" r="14" />
      <rect x="0" y="-1" width="8" height="2" fill={color} stroke="none" />
    </g>
    {/* bottom-left (flipped Y) */}
    <g transform="translate(20,60) scale(1,-1)">
      <circle cx="0" cy="0" r="14" />
      <rect x="0" y="-1" width="8" height="2" fill={color} stroke="none" />
    </g>
    {/* bottom-right */}
    <g transform="translate(60,60) scale(-1,-1)">
      <circle cx="0" cy="0" r="14" />
      <rect x="0" y="-1" width="8" height="2" fill={color} stroke="none" />
    </g>
    {/* bloom at intersection - a diamond + center dot */}
    <g transform="translate(40,40) rotate(45)">
      <rect x="-4" y="-4" width="8" height="8" fill={color} stroke="none" opacity="0.9" />
    </g>
    <circle cx="40" cy="40" r="1.5" fill={color} stroke="none" />
  </svg>
);

// Seamless pattern tile — used as background data-url.
const buildPatternDataUrl = (color = "#C4973A", opacity = 0.3) => {
  const c = color;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180">
    <g fill="none" stroke="${c}" stroke-opacity="${opacity}" stroke-width="1">
      <circle cx="45" cy="45" r="22"/>
      <circle cx="135" cy="45" r="22"/>
      <circle cx="45" cy="135" r="22"/>
      <circle cx="135" cy="135" r="22"/>
      <rect x="45" y="44" width="12" height="2" fill="${c}" fill-opacity="${opacity}" stroke="none"/>
      <rect x="123" y="44" width="12" height="2" fill="${c}" fill-opacity="${opacity}" stroke="none"/>
      <rect x="45" y="134" width="12" height="2" fill="${c}" fill-opacity="${opacity}" stroke="none"/>
      <rect x="123" y="134" width="12" height="2" fill="${c}" fill-opacity="${opacity}" stroke="none"/>
    </g>
    <g fill="${c}" fill-opacity="${opacity * 0.8}">
      <rect x="86" y="86" width="8" height="8" transform="rotate(45 90 90)"/>
      <rect x="86" y="-4" width="8" height="8" transform="rotate(45 90 0)"/>
      <rect x="-4" y="86" width="8" height="8" transform="rotate(45 0 90)"/>
      <rect x="176" y="86" width="8" height="8" transform="rotate(45 180 90)"/>
      <rect x="86" y="176" width="8" height="8" transform="rotate(45 90 180)"/>
    </g>
  </svg>`;
  return `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`;
};

// Divider: hairline with a single bloom in the middle.
const BloomRule = ({ color = "#C4973A" }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 16, color, opacity: 0.6 }}>
    <div style={{ flex: 1, height: 1, background: "currentColor", opacity: 0.4 }} />
    <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
      <rect x="5" y="5" width="8" height="8" transform="rotate(45 9 9)" fill={color} opacity="0.8" />
    </svg>
    <div style={{ flex: 1, height: 1, background: "currentColor", opacity: 0.4 }} />
  </div>
);

// Small right arrow.
const ArrowNE = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.3">
    <path d="M3 11 L11 3" />
    <path d="M5 3 L11 3 L11 9" />
  </svg>
);

const ArrowRight = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.4">
    <path d="M2 7 L12 7" />
    <path d="M8 3 L12 7 L8 11" />
  </svg>
);

const ArrowLeft = ({ size = 14 }) => (
  <svg width={size} height={size} viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.4">
    <path d="M12 7 L2 7" />
    <path d="M6 3 L2 7 L6 11" />
  </svg>
);

// The G wordmark — simple tracked-out text, matches guidelines rule (gold/black/ivory).
const GildanaMark = ({ color = "#0a0a0a" }) => (
  <span style={{
    fontFamily: "'Bebas Neue', sans-serif",
    letterSpacing: "0.32em",
    fontSize: 22,
    color,
  }}>
    GILDANA
  </span>
);

Object.assign(window, { GEmblem, buildPatternDataUrl, BloomRule, ArrowNE, ArrowRight, ArrowLeft, GildanaMark });
