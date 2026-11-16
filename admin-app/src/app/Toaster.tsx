import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from "react";
import ToastViewport, { type ToastItem, type ToastKind } from "@/components/toast-stack";

type Push = (kind: ToastKind, title: string, description?: string) => void;
const Ctx = createContext<Push>(() => {});

export function ToasterProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const seq = useRef(0);
  const dismiss = useCallback((id: string) => setItems((p) => p.filter((t) => t.id !== id)), []);
  const push = useCallback<Push>((kind, title, description) => {
    seq.current += 1;
    setItems((p) => [{ id: `t${seq.current}`, kind, title, description }, ...p].slice(0, 4));
  }, []);
  return (
    <Ctx.Provider value={push}>
      {children}
      <ToastViewport items={items} onDismiss={dismiss} />
    </Ctx.Provider>
  );
}

export const useToast = () => useContext(Ctx);
