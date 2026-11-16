import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, createElement } from "react";
import { createRoot, type Root } from "react-dom/client";
import DeleteButton from "./delete-button";

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let host: HTMLElement;

beforeEach(() => {
  vi.useFakeTimers();
  host = document.createElement("div");
  document.body.appendChild(host);
  root = createRoot(host);
});

afterEach(() => {
  act(() => root.unmount());
  host.remove();
  vi.useRealTimers();
});

function render(onConfirm: () => void | Promise<unknown>, tick = 0) {
  act(() =>
    root.render(
      createElement(DeleteButton, { label: "پایان تخفیف", cancelLabel: "منصرف شدم", countdown: 3, onConfirm, workingLabel: "در حال پایان دادن…", key: "b", "data-tick": tick } as never),
    ),
  );
}

// During the swap animation the outgoing button is still in the DOM: always pick the
// button that is currently shown (the last one rendered).
const current = () => {
  const all = host.querySelectorAll("button");
  return all[all.length - 1] as HTMLButtonElement;
};
const click = () => act(() => current().click());
const advance = (ms: number) => act(() => void vi.advanceTimersByTime(ms));

describe("countdown button (پایان تخفیف)", () => {
  it("fires on time even while the page re-renders (polling)", () => {
    const onConfirm = vi.fn(() => new Promise(() => {}));
    render(onConfirm);
    click();
    // The campaign page re-renders every few hundred ms while polling: a new onConfirm each time.
    for (let i = 0; i < 12; i++) {
      render(onConfirm, i);
      advance(300);
    }
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it("shows a working state instead of sitting on zero", () => {
    render(() => new Promise(() => {}));
    click();
    advance(3500);
    expect(current().textContent).toContain("در حال پایان دادن…");
    expect(current().textContent).not.toContain("منصرف شدم");
    expect(current().disabled).toBe(true);
  });

  it("comes back to normal when the action fails", async () => {
    render(() => Promise.reject(new Error("503")));
    click();
    advance(3500);
    await act(async () => {
      await Promise.resolve();
      await Promise.resolve();
    });
    advance(1000);
    // The normal button is back and usable (the outgoing one may still be animating out).
    const back = [...host.querySelectorAll("button")].find((b) => b.textContent === "پایان تخفیف");
    expect(back).toBeDefined();
    expect(back!.disabled).toBe(false);
  });

  it("cancel during the countdown never fires", () => {
    const onConfirm = vi.fn();
    render(onConfirm);
    click();
    advance(1000);
    expect(current().textContent).toContain("منصرف شدم");
    click(); // «منصرف شدم»
    advance(5000);
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
