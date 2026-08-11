const { useState } = React;

/* ================= NAV ================= */
const Nav = () => (
  <nav className="nav">
    <div className="brand">
      <span className="name">GILDANA</span>
      <span className="tag">WHERE BRANDS BECOME ICONS</span>
    </div>
    <ul className="nav-links">
      <li><a href="#top" className="active">Home</a></li>
      <li><a href="#about">About</a></li>
      <li><a href="#services">Services</a></li>
      <li><a href="#work">Work</a></li>
      <li><a href="#testimonials">Testimonials</a></li>
      <li><a href="#contact">Contact</a></li>
    </ul>
    <a href="#contact" className="nav-cta">
      Let's Connect
      <ArrowRight size={12} />
    </a>
  </nav>
);

/* ================= HERO ================= */
const Hero = () => (
  <section className="hero" id="top">
    <div className="hero-grid">
      <div className="hero-text">
        <div className="eyebrow">360° Marketing Agency</div>
        <h1 className="hero-headline">
          We build brands
          <span className="gold">that lead &amp; last</span>
        </h1>
        <div className="hero-rule" />
        <p className="hero-lede">
          A full-service 360° marketing agency helping ambitious brands grow, connect, and stand out in a constantly changing world.
        </p>
        <div className="hero-cta-row">
          <a href="#services" className="btn btn-primary">
            Our Services
            <span className="arrow"><ArrowRight size={12} /></span>
          </a>
          <a href="#work" className="btn btn-ghost">
            View Our Work
            <span className="arrow"><ArrowRight size={12} /></span>
          </a>
        </div>
      </div>
      <div className="hero-photo">
        <img src="images/hero-team.jpg" alt="Gildana team" className="hero-photo-img" />
        <div className="strategy-card">
          <span>STRATEGY</span>
          <span>CREATIVE</span>
          <span>CONTENT</span>
          <span>RESULTS</span>
        </div>
      </div>
    </div>
  </section>
);

/* ================= STATS ================= */
const Stats = () => (
  <section className="stats">
    <div className="stats-inner">
      <div className="stats-eyebrow">TRUSTED BY GROWING BRANDS</div>
      <div className="stats-grid">
        <div className="stat">
          <div className="num">120<span style={{ color: "var(--gold-soft)" }}>+</span></div>
          <div className="label">CLIENTS</div>
          <div className="sub">Long-term partnerships</div>
        </div>
        <div className="stat">
          <div className="num">250<span style={{ color: "var(--gold-soft)" }}>+</span></div>
          <div className="label">PROJECTS</div>
          <div className="sub">Delivered with impact</div>
        </div>
        <div className="stat">
          <div className="num">15<span style={{ color: "var(--gold-soft)" }}>+</span></div>
          <div className="label">INDUSTRIES</div>
          <div className="sub">Deep market experience</div>
        </div>
        <div className="stat">
          <div className="num">20<span style={{ color: "var(--gold-soft)" }}>+</span></div>
          <div className="label">COUNTRIES</div>
          <div className="sub">Local insight, global reach</div>
        </div>
      </div>
      <div className="stats-cta">
        <a href="#work">VIEW OUR WORK →</a>
      </div>
    </div>
  </section>
);

/* ================= SERVICES ================= */
const SERVICES = [
  { Icon: IconStrategy, name: "STRATEGY", desc: "Data-driven strategies that align with your business goals." },
  { Icon: IconBranding, name: "BRANDING", desc: "Build a powerful brand identity that connects and inspires." },
  { Icon: IconMegaphone, name: "DIGITAL MARKETING", desc: "Reach the right audience across digital channels that matter." },
  { Icon: IconBox, name: "CONTENT CREATION", desc: "Creative content that tells your story and drives engagement." },
  { Icon: IconChart, name: "PERFORMANCE MARKETING", desc: "Campaigns optimized for results, leads, and ROI." },
  { Icon: IconCode, name: "WEB & TECHNOLOGY", desc: "Beautiful websites and smart solutions that power your brand." },
];

const Services = () => (
  <section className="services" id="services">
    <div className="services-inner">
      <div className="services-head">
        <div className="eyebrow">OUR SERVICES</div>
        <h2>Complete 360° <span className="gold">marketing solutions</span></h2>
      </div>
      <div className="services-grid">
        {SERVICES.map(({ Icon, name, desc }) => (
          <div key={name} className="service-card">
            <div className="icon"><Icon /></div>
            <h3>{name}</h3>
            <p>{desc}</p>
            <span className="arrow"><ArrowRight size={14} /></span>
          </div>
        ))}
      </div>
    </div>
  </section>
);

/* ================= PORTFOLIO ================= */
const CASES = [
  { img: "images/case-skyline.jpg", cat: "REAL ESTATE", title: "Skyline Developments", pct: "+42%", label: "Qualified Leads" },
  { img: "images/case-hotel.jpg", cat: "HOSPITALITY", title: "Azure Hotel Collection", pct: "+35%", label: "Direct Bookings" },
  { img: "images/case-sport.jpg", cat: "SPORTS", title: "Peak Performance", pct: "+60%", label: "Brand Engagement" },
  { img: "images/case-fragrance.jpg", cat: "RETAIL", title: "Noir Fragrances", pct: "+28%", label: "Sales Increase" },
];

const Portfolio = () => (
  <section className="portfolio" id="work">
    <div className="portfolio-inner">
      <div className="portfolio-head">
        <div className="left">
          <div className="eyebrow">OUR PORTFOLIO</div>
          <h2>Work that drives results</h2>
        </div>
        <a href="#" className="view-all">VIEW ALL WORK →</a>
      </div>
      <div className="portfolio-grid">
        {CASES.map((c) => (
          <article key={c.title} className="case">
            <img className="case-img" src={c.img} alt={c.title} />
            <div className="overlay" />
            <div className="meta">
              <div className="cat">{c.cat}</div>
              <div className="title">{c.title}</div>
              <div className="stat-row">
                <div>
                  <div className="stat-pct">{c.pct}</div>
                  <div className="stat-label">{c.label}</div>
                </div>
                <span className="pct-arrow"><ArrowRight size={14} /></span>
              </div>
            </div>
          </article>
        ))}
      </div>
    </div>
  </section>
);

/* ================= TESTIMONIALS ================= */
const TESTIMONIALS = [
  { quote: "Gildana transformed our brand from the inside out. Their strategy, creativity, and execution delivered results beyond what we expected.", who: "AHMED EL-NASSY", role: "CEO, Skyline Developments" },
  { quote: "Working with Gildana felt like adding a strategic partner, not an agency. Every campaign hit harder, and our digital presence has never looked sharper.", who: "MONA SHAHEEN", role: "CMO, Azure Hotel Collection" },
  { quote: "They understood our brand instantly and turned it into a story that resonates. Engagement, leads, and recognition all moved in the right direction.", who: "OMAR FAROUK", role: "Founder, Peak Performance" },
];

const Testimonials = () => {
  const [idx, setIdx] = useState(0);
  const t = TESTIMONIALS[idx];
  return (
    <section className="testimonial" id="testimonials">
      <div className="testimonial-inner">
        <div className="eyebrow">TESTIMONIALS</div>
        <button className="testimonial-arrow prev" onClick={() => setIdx((idx - 1 + TESTIMONIALS.length) % TESTIMONIALS.length)} aria-label="Previous">
          <ArrowLeft size={12} />
        </button>
        <button className="testimonial-arrow next" onClick={() => setIdx((idx + 1) % TESTIMONIALS.length)} aria-label="Next">
          <ArrowRight size={12} />
        </button>
        <div className="testimonial-quote">
          <span className="q-mark left">“</span>
          <p>{t.quote}</p>
          <span className="q-mark right">“</span>
        </div>
        <div className="testimonial-attr">
          <div className="who">{t.who}</div>
          <div className="role">{t.role}</div>
        </div>
        <div className="testimonial-dots">
          {TESTIMONIALS.map((_, i) => (
            <button key={i} className={`dot${i === idx ? " active" : ""}`} onClick={() => setIdx(i)} aria-label={`Slide ${i + 1}`} />
          ))}
        </div>
      </div>
    </section>
  );
};

/* ================= CLIENTS ================= */
const Clients = () => (
  <section className="clients">
    <div className="clients-inner">
      <div className="clients-eyebrow">TRUSTED BY LEADING BRANDS</div>
      <div className="clients-grid">
        <div className="client"><img src="images/client-emaar.png" alt="Emaar" /></div>
        <div className="client"><img src="images/client-marriott.png" alt="Marriott" /></div>
        <div className="client"><img src="images/client-orascom.png" alt="Orascom Development" /></div>
        <div className="client"><img src="images/client-cityedge.png" alt="City Edge Developments" /></div>
        <div className="client"><img src="images/client-dorra.png" alt="Dorra Group" /></div>
      </div>
    </div>
  </section>
);

/* ================= CONTACT ================= */
const ContactForm = () => {
  const [form, setForm] = useState({ name: "", phone: "", email: "", company: "", message: "" });
  const [sent, setSent] = useState(false);
  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const submit = (e) => {
    e.preventDefault();
    if (!form.name || !form.email) return;
    setSent(true);
  };

  if (sent) {
    return (
      <div className="contact-form-wrap">
        <div className="contact-sent">
          <strong>Thank you, {form.name.split(" ")[0]}.</strong> Your message is with our team — we'll be in touch within two working days.
        </div>
      </div>
    );
  }
  return (
    <div className="contact-form-wrap">
      <form className="contact-form" onSubmit={submit}>
        <div className="row-2">
          <div className="field">
            <input type="text" placeholder="Your Name" value={form.name} onChange={(e) => set("name", e.target.value)} />
          </div>
          <div className="field">
            <input type="tel" placeholder="Phone Number" value={form.phone} onChange={(e) => set("phone", e.target.value)} />
          </div>
        </div>
        <div className="row-2">
          <div className="field">
            <input type="email" placeholder="Email Address" value={form.email} onChange={(e) => set("email", e.target.value)} />
          </div>
          <div className="field">
            <input type="text" placeholder="Company Name" value={form.company} onChange={(e) => set("company", e.target.value)} />
          </div>
        </div>
        <div className="field">
          <textarea placeholder="Tell us about your project" value={form.message} onChange={(e) => set("message", e.target.value)} />
        </div>
        <button type="submit" className="submit">
          Send Message
          <ArrowRight size={12} />
        </button>
      </form>
    </div>
  );
};

const Contact = () => (
  <section className="contact" id="contact">
    <div className="contact-inner">
      <div className="contact-side">
        <div className="eyebrow">LET'S WORK TOGETHER</div>
        <h2>Start your<br/>next project</h2>
        <p className="lede">Tell us about your business and let's create something amazing together.</p>
        <div className="contact-info-list">
          <div className="contact-info-row">
            <span className="icon-circle"><IconPhone /></span>
            <span>+20 101 123 4567</span>
          </div>
          <div className="contact-info-row">
            <span className="icon-circle"><IconMail /></span>
            <span>hello@gildana.com</span>
          </div>
          <div className="contact-info-row">
            <span className="icon-circle"><IconPin /></span>
            <span>Cairo, Egypt</span>
          </div>
        </div>
      </div>
      <ContactForm />
    </div>
    <a className="float-cta" href="#contact" aria-label="Chat"><IconChat /></a>
  </section>
);

/* ================= FOOTER ================= */
const Footer = () => (
  <footer className="footer">
    <div className="footer-inner">
      <div className="footer-grid">
        <div className="footer-brand">
          <div className="name">GILDANA</div>
          <div className="tag">WHERE BRANDS BECOME ICONS</div>
        </div>
        <div className="footer-col">
          <h4>QUICK LINKS</h4>
          <ul>
            <li><a href="#top">Home</a></li>
            <li><a href="#about">About Us</a></li>
            <li><a href="#services">Services</a></li>
          </ul>
        </div>
        <div className="footer-col">
          <h4>&nbsp;</h4>
          <ul>
            <li><a href="#work">Work</a></li>
            <li><a href="#testimonials">Testimonials</a></li>
            <li><a href="#contact">Contact</a></li>
          </ul>
        </div>
        <div className="footer-col footer-newsletter">
          <h4>FOLLOW US</h4>
          <div className="footer-social">
            <a href="#" aria-label="LinkedIn"><SocialLI /></a>
            <a href="#" aria-label="Instagram"><SocialIG /></a>
            <a href="#" aria-label="Facebook"><SocialFB /></a>
            <a href="#" aria-label="Behance"><SocialBE /></a>
          </div>
          <h4 style={{ marginBottom: 8 }}>NEWSLETTER</h4>
          <p>Stay updated with our latest insights</p>
          <form onSubmit={(e) => e.preventDefault()}>
            <input type="email" placeholder="Your Email" />
            <button type="submit">Join</button>
          </form>
        </div>
      </div>
      <div className="footer-bottom">
        <span>© 2026 Gildana. All Rights Reserved</span>
        <div className="right">
          <a href="#">Privacy Policy</a>
          <a href="#">Terms &amp; Conditions</a>
        </div>
      </div>
    </div>
  </footer>
);

Object.assign(window, { Nav, Hero, Stats, Services, Portfolio, Testimonials, Clients, Contact, Footer });
