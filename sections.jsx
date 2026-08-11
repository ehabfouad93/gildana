/* Gildana sections — Nav, Hero, Manifesto, Services, Stats, Work, Clients, Testimonial, Contact, Footer */

const { useEffect, useRef, useState } = React;

// ---- Reveal on scroll (very quiet) ----
const useReveal = () => {
  const ref = useRef(null);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) {
            e.target.classList.add("in");
            io.unobserve(e.target);
          }
        });
      },
      { threshold: 0.12 }
    );
    el.querySelectorAll(".reveal").forEach((n) => io.observe(n));
    return () => io.disconnect();
  }, []);
  return ref;
};

/* ============================================================ */
const Nav = () => {
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const on = () => setScrolled(window.scrollY > 40);
    on();
    window.addEventListener("scroll", on, { passive: true });
    return () => window.removeEventListener("scroll", on);
  }, []);
  return (
    <nav className={`nav${scrolled ? " scrolled" : ""}`} style={{ backgroundColor: "rgba(246, 240, 224, 0.5)" }}>
      <a href="#top" className="nav-logo">
        GILDANA<span className="dot">.</span>
      </a>
      <ul className="nav-links">
        <li><a href="#work">Work</a></li>
        <li><a href="#services">Services</a></li>
        <li><a href="#about">Studio</a></li>
        <li><a href="#journal">Journal</a></li>
        <li><a href="#contact">Contact</a></li>
      </ul>
      <a href="#contact" className="nav-cta">Start a Project</a>
    </nav>);

};

/* ============================================================ */
const Hero = () => {
  const patternUrl = buildPatternDataUrl("#4B3621", 0.8);
  return (
    <header className="hero" id="top">
      <div className="hero-bg-pattern" style={{ backgroundImage: patternUrl }} />
      <div className="hero-grid">
        <div className="hero-eyebrow-row">
          <span className="eyebrow">01 — The Studio</span>
          <span className="loc">Est. 2014 · MENA &amp; Global</span>
        </div>

        <h1 className="hero-headline reveal in">
          <span className="line">Where brands</span>
          <span className="line">become <span className="gold">icons</span></span>
        </h1>

        <div className="hero-meta reveal in">
          <p className="lede">A full-service creative agency crafting identities, campaigns, and digital experiences that endure.</p>
          <p>Branding. Digital marketing. Web design. Content &amp; media. Strategy. Events.</p>
        </div>

        <div className="hero-ornament">
          <GEmblem size={120} color="#C4973A" strokeWidth={0.8} />
        </div>
      </div>

      <div className="hero-footer">
        <span>© Gildana · 2026</span>
        <div className="scroll">
          <span>Scroll</span>
          <span className="line-anim" />
        </div>
      </div>
    </header>);

};

/* ============================================================ */
const Manifesto = () =>
<section className="manifesto" id="about">
    <div className="manifesto-inner">
      <div className="manifesto-head reveal">
        <span className="section-num">— 02</span>
        <h2>Philosophy</h2>
      </div>
      <div className="manifesto-body reveal">
        <p className="pull">
          We believe the most enduring brands are not <em>designed</em> — they are <em>composed</em>. A quiet rhythm of detail, intent, and restraint.
        </p>
        <p>
          Gildana is a 360° marketing studio built on the belief that elegance and strategy are inseparable. We partner with ambitious teams across hospitality, fashion, finance, and lifestyle to shape identities that don't shout — they resonate.
        </p>
        <p>
          From brand architecture to the pixels of your digital storefront, every decision is guided by a single question: <em>will this still feel right in ten years?</em>
        </p>
      </div>
    </div>
  </section>;


/* ============================================================ */
const SERVICES = [
{ num: "01", name: "Digital Marketing", italic: "performance", desc: "Paid media, SEO, CRM, and lifecycle marketing engineered for measurable growth without compromising the brand voice.", featured: true, tag: "Core Practice" },
{ num: "02", name: "Web Design", italic: "& build", desc: "Editorial websites, e-commerce, and interactive experiences designed in-house and shipped by our engineering team.", featured: true, tag: "Core Practice" },
{ num: "03", name: "Branding", italic: "& identity", desc: "Logos, wordmarks, visual systems, and brand architecture that scale from stationery to skyscraper." },
{ num: "04", name: "Content", italic: "& film", desc: "Photography, motion, art direction, and editorial content produced in our in-house studio." },
{ num: "05", name: "Strategy", italic: "& research", desc: "Positioning, audience research, market analysis, and the creative strategy that precedes every great campaign." },
{ num: "06", name: "Media", italic: "planning", desc: "Above-the-line, out-of-home, and integrated media buying across regional and global markets." },
{ num: "07", name: "Events", italic: "& activation", desc: "Launches, exhibitions, and experiential programs — from concept through on-the-ground production." }];


const Services = () =>
<section className="services" id="services">
    <div className="services-head">
      <span className="section-num">— 03</span>
      <h2>
        A studio of <em>many crafts</em>
      </h2>
      <p className="kicker">Seven disciplines, one editorial voice. Engaged independently or composed into a full 360° program.</p>
    </div>

    <ul className="services-list">
      {SERVICES.map((s) =>
    <li key={s.num} className={`service-row reveal${s.featured ? " featured" : ""}`}>
          <span className="num">{s.num}</span>
          <div>
            <span className="name">
              {s.name}
              <em>{s.italic}</em>
            </span>
            {s.featured && <div><span className="tag">{s.tag}</span></div>}
          </div>
          <p className="desc">{s.desc}</p>
          <span className="arrow"><ArrowNE /></span>
        </li>
    )}
    </ul>
  </section>;


/* ============================================================ */
const Stats = () => {
  const patternUrl = buildPatternDataUrl("#C4973A", 0.8);
  return (
    <section className="stats">
      <div className="stats-pattern" style={{ backgroundImage: patternUrl }} />
      <div className="stats-head">
        <span className="eyebrow">— 04 · In numbers</span>
        <h3>A decade of iconic work.</h3>
      </div>

      <div className="stats-grid">
        <div className="stat reveal">
          <div className="big">12<span className="unit">years</span></div>
          <div className="label">In practice</div>
          <div className="tiny">Since 2014</div>
        </div>
        <div className="stat reveal">
          <div className="big">240<span className="unit">+</span></div>
          <div className="label">Brands shaped</div>
          <div className="tiny">from Riyadh to Milan</div>
        </div>
        <div className="stat reveal">
          <div className="big">38<span className="unit">awards</span></div>
          <div className="label">Recognition</div>
          <div className="tiny">Cannes · D&amp;AD · Dubai Lynx</div>
        </div>
        <div className="stat reveal">
          <div className="big">96<span className="unit">%</span></div>
          <div className="label">Client retention</div>
          <div className="tiny">Y-over-Y average</div>
        </div>
      </div>
    </section>);

};

/* ============================================================ */
const WORK = [
{ cls: "c7", art: "art-a", badge: "MAISON VERGE", title: "Maison Vergé", sub: "A new couture house, renewed", cat: "Branding · Web", dark: false },
{ cls: "c5", art: "art-b", badge: "AURELIA", title: "Aurelia Hotels", sub: "The quiet language of luxury", cat: "Digital · Media", dark: true },
{ cls: "c5", art: "art-c", badge: "ORO PRIVATE", title: "Oro Private Bank", sub: "Wealth, understated", cat: "Strategy · Identity", dark: false },
{ cls: "c7", art: "art-e", badge: "ATELIER DUNE", title: "Atelier Dune", sub: "Eighty-four days in the desert", cat: "Content · Film", dark: false },
{ cls: "c6", art: "art-f", badge: "NOCTURNE FRAGRANCE", title: "Nocturne", sub: "An olfactory launch, reimagined", cat: "Campaign · Events", dark: false },
{ cls: "c6", art: "art-d", badge: "CASA SALE", title: "Casa Sale", sub: "Hospitality at the edge of the Aegean", cat: "Web Design · Brand", dark: true }];


const Work = () => {
  const [filter, setFilter] = useState("All");
  const filters = ["All", "Branding", "Digital", "Web Design", "Campaigns", "Content"];
  return (
    <section className="work" id="work">
      <div className="work-head">
        <span className="section-num">— 05</span>
        <h2>Selected <em>work</em></h2>
        <div className="filters">
          {filters.map((f) =>
          <button key={f} className={f === filter ? "active" : ""} onClick={() => setFilter(f)}>
              {f}
            </button>
          )}
        </div>
      </div>

      <div className="work-grid">
        {WORK.map((w, i) =>
        <article key={i} className={`work-item reveal ${w.cls}`}>
            <div className={`frame ${w.art}`}>
              <div className="art-placeholder-stripes" />
              <span className={`art-badge${w.dark ? " dark" : ""}`}>{w.badge}</span>
              <div className="placeholder"><span>CASE STUDY IMAGE</span></div>
            </div>
            <div className="meta">
              <div>
                <div className="title">{w.title}</div>
                <div className="sub">{w.sub}</div>
              </div>
              <div className="cat">{w.cat}</div>
            </div>
          </article>
        )}
      </div>
    </section>);

};

/* ============================================================ */
const CLIENTS = [
{ label: "MAISON VERGÉ", variant: "mono" },
{ label: "AURELIA", variant: "default" },
{ label: "ORO PRIVÉ", variant: "mono" },
{ label: "ATELIER DUNE", variant: "default" },
{ label: "NOCTURNE", variant: "small" },
{ label: "CASA SALE", variant: "default" },
{ label: "KHALIFA GROUP", variant: "default" },
{ label: "MARÉE", variant: "mono" },
{ label: "HOUSE OF SAHAR", variant: "small" },
{ label: "VELVET & OAK", variant: "default" },
{ label: "ZAHRA COUTURE", variant: "mono" },
{ label: "TERRA NOVA", variant: "default" }];


const Clients = () =>
<section className="clients">
    <div className="clients-head reveal">
      <span className="eyebrow">— 06 · In good company</span>
      <h4>Partners who believe the details matter.</h4>
    </div>
    <div className="clients-grid reveal">
      {CLIENTS.map((c, i) =>
    <div key={i} className="client">
          <span className={c.variant === "mono" ? "mono" : c.variant === "small" ? "small" : ""}>{c.label}</span>
        </div>
    )}
    </div>
  </section>;


/* ============================================================ */
const TESTIMONIALS = [
{
  quote: "Gildana didn't redesign our brand — they <em>re-authored</em> it. Three years on, every photograph, every campaign, every boutique still feels like it was written in the same hand.",
  who: "Leïla Al-Farhan",
  role: "Creative Director, Maison Vergé",
  initials: "LF"
},
{
  quote: "The most <em>quietly strategic</em> studio we've ever engaged. Our digital conversion doubled, and not a single visitor would guess we were optimizing for it.",
  who: "Marc Ibrahim",
  role: "CMO, Aurelia Hotels",
  initials: "MI"
},
{
  quote: "They treat a website the way a couturier treats a gown — every seam <em>considered</em>, every drape intentional. The result speaks for itself.",
  who: "Yara Kassem",
  role: "Founder, Atelier Dune",
  initials: "YK"
}];


const Testimonial = () => {
  const [idx, setIdx] = useState(0);
  const t = TESTIMONIALS[idx];
  const next = () => setIdx((i) => (i + 1) % TESTIMONIALS.length);
  const prev = () => setIdx((i) => (i - 1 + TESTIMONIALS.length) % TESTIMONIALS.length);
  return (
    <section className="testimonial">
      <div className="testimonial-ornament">
        <GEmblem size={320} color="#C4973A" strokeWidth={0.4} />
      </div>
      <div className="testimonial-inner reveal">
        <div className="eyebrow">— 07 · Clients</div>
        <blockquote className="quote">
          <span className="mark">“</span>
          <span dangerouslySetInnerHTML={{ __html: t.quote }} />
        </blockquote>
        <div className="testimonial-meta">
          <div className="avatar">{t.initials}</div>
          <div>
            <div className="who">{t.who.toUpperCase()}</div>
            <div className="role">{t.role}</div>
          </div>
        </div>

        <div className="testimonial-dots">
          {TESTIMONIALS.map((_, i) =>
          <span key={i} className={`dot${i === idx ? " active" : ""}`} />
          )}
        </div>
        <div className="testimonial-nav">
          <button onClick={prev} aria-label="Previous"><ArrowLeft /></button>
          <button onClick={next} aria-label="Next"><ArrowRight /></button>
        </div>
      </div>
    </section>);

};

/* ============================================================ */
const ContactForm = () => {
  const [form, setForm] = useState({ name: "", email: "", company: "", message: "", budget: "", services: [] });
  const [sent, setSent] = useState(false);

  const budgets = ["< $25K", "$25–75K", "$75–150K", "$150K+"];
  const serviceOptions = ["Branding", "Digital Marketing", "Web Design", "Content", "Strategy", "Events"];

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const toggleService = (s) => {
    setForm((f) => ({
      ...f,
      services: f.services.includes(s) ? f.services.filter((x) => x !== s) : [...f.services, s]
    }));
  };

  const submit = (e) => {
    e.preventDefault();
    if (!form.name || !form.email || !form.message) return;
    setSent(true);
  };

  if (sent) {
    return (
      <div className="form-sent">
        <strong>Thank you, {form.name.split(" ")[0]}.</strong>
        Your note is with our team. You'll hear from a Gildana partner within two working days.
      </div>);

  }

  return (
    <form className="contact-form" onSubmit={submit}>
      <div className="row-2">
        <div className={`field${form.name ? " has-value" : ""}`}>
          <label>Your name</label>
          <input value={form.name} onChange={(e) => set("name", e.target.value)} />
        </div>
        <div className={`field${form.email ? " has-value" : ""}`}>
          <label>Email address</label>
          <input type="email" value={form.email} onChange={(e) => set("email", e.target.value)} />
        </div>
      </div>

      <div className={`field${form.company ? " has-value" : ""}`}>
        <label>Company / Brand</label>
        <input value={form.company} onChange={(e) => set("company", e.target.value)} />
      </div>

      <div className={`field has-value`} style={{ paddingBottom: 18 }}>
        <label>Services of interest</label>
        <div className="services-chips">
          {serviceOptions.map((s) =>
          <button
            type="button"
            key={s}
            className={`chip${form.services.includes(s) ? " active" : ""}`}
            onClick={() => toggleService(s)}>
            
              {s}
            </button>
          )}
        </div>
      </div>

      <div className={`field has-value`} style={{ paddingBottom: 18 }}>
        <label>Project budget</label>
        <div className="budget-row">
          {budgets.map((b) =>
          <button
            type="button"
            key={b}
            className={`chip${form.budget === b ? " active" : ""}`}
            onClick={() => set("budget", b)}>
            
              {b}
            </button>
          )}
        </div>
      </div>

      <div className={`field${form.message ? " has-value" : ""}`}>
        <label>Tell us about the project</label>
        <textarea rows="3" value={form.message} onChange={(e) => set("message", e.target.value)} />
      </div>

      <div className="form-submit">
        <p className="note">We read every enquiry personally. Please allow up to two working days for a reply.</p>
        <button type="submit" className="submit-btn">
          Send enquiry
          <span className="arrow"><ArrowRight size={12} /></span>
        </button>
      </div>
    </form>);

};

const Contact = () => {
  const patternUrl = buildPatternDataUrl("#C4973A", 0.7);
  return (
    <section className="contact" id="contact">
      <div className="contact-pattern" style={{ backgroundImage: patternUrl }} />
      <div className="contact-inner">
        <div className="contact-left reveal">
          <span className="eyebrow">— 08 · Enquiries</span>
          <h2>Let's compose <em>something iconic</em>.</h2>
          <p className="lede">For new projects, partnerships, or the occasional thoughtful introduction — we'd be delighted to hear from you.</p>

          <div className="contact-info">
            <div className="cell">
              <div className="label">New Business</div>
              <div className="value"><a href="mailto:studio@gildana.co">info@gildana.net</a></div>
            </div>
            <div className="cell">
              <div className="label">Press</div>
              <div className="value"><a href="mailto:press@gildana.co">press@gildana.net</a></div>
            </div>
            <div className="cell">
              <div className="label">EGYPT STUDIO</div>
              <div className="value">King Fahd Road, Olaya<br />Riyadh 12244, KSA</div>
            </div>
            <div className="cell">
              <div className="label">Dubai Studio</div>
              <div className="value">DIFC Gate Village 11<br />Dubai, UAE</div>
            </div>
          </div>
        </div>
        <div className="reveal">
          <ContactForm />
        </div>
      </div>
    </section>);

};

/* ============================================================ */
const Footer = () =>
<footer className="footer">
    <div className="footer-huge">GILDANA<span className="dot">.</span></div>
    <div className="footer-bottom">
      <div>© 2026 Gildana Studio · All rights reserved</div>
      <div className="links">
        <a href="#">Instagram</a>
        <a href="#">LinkedIn</a>
        <a href="#">Behance</a>
        <a href="#">Journal</a>
      </div>
      <div className="right">Where brands become icons</div>
    </div>
  </footer>;


Object.assign(window, { Nav, Hero, Manifesto, Services, Stats, Work, Clients, Testimonial, Contact, Footer, useReveal });