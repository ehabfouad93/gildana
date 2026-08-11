const App = () => {
  const ref = useReveal();
  return (
    <div ref={ref}>
      <Nav />
      <Hero />
      <Manifesto />
      <Services />
      <Stats />
      <Work />
      <Clients />
      <Testimonial />
      <Contact />
      <Footer />
    </div>
  );
};

ReactDOM.createRoot(document.getElementById("app")).render(<App />);
