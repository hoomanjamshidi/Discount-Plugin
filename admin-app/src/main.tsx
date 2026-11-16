import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { MotionConfig } from "motion/react";
import "./index.css";
import { App } from "./app/App";
import { ToasterProvider } from "./app/Toaster";

const el = document.getElementById("dsd-root");
if (el) {
  createRoot(el).render(
    <StrictMode>
      <MotionConfig reducedMotion="user">
        <ToasterProvider>
          <App />
        </ToasterProvider>
      </MotionConfig>
    </StrictMode>,
  );
}
