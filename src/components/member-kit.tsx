import { Link, useNavigate } from "@tanstack/react-router";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useState, type ReactNode } from "react";
import { memberApi, memberToken } from "@/lib/member-api";

/** Menu mirrors the reference kit's ddsmoothmenu structure. */
const MENU: { label: string; items: { to: string; label: string }[] }[] = [
  {
    label: "Account State",
    items: [
      { to: "/member", label: "Dashboard" },
      { to: "/member/commission", label: "Bonus Report" },
      { to: "/member/profile", label: "Profile View/Edit" },
      { to: "/member/change-password", label: "Password Change/Reset" },
      { to: "/member/notice", label: "Account Notice" },
    ],
  },
  {
    label: "Sales View",
    items: [
      { to: "/member/tree", label: "Genealogy Tree" },
      { to: "/member/sponsors", label: "Sponsors List" },
      { to: "/member/statistics", label: "Statistics View" },
      { to: "/member/activation", label: "Package Activation" },
    ],
  },
  {
    label: "Withdrawals",
    items: [
      { to: "/member/withdrawals", label: "Withdrawals Form" },
      { to: "/member/withdrawals-report", label: "Withdrawals Reports" },
    ],
  },
];

export function useMember<T>(key: string, path: string, enabled = true) {
  return useQuery({ queryKey: ["member", key], queryFn: () => memberApi<T>(path), enabled, retry: false });
}

export function MemberShell({ children }: { children: ReactNode }) {
  const navigate = useNavigate();
  const [ready, setReady] = useState(false);
  const [open, setOpen] = useState<number | null>(null);

  useEffect(() => {
    if (!memberToken.get()) navigate({ to: "/login" });
    else setReady(true);
  }, [navigate]);

  const me = useMember<{ name: string }>("me", "/me", ready);
  useEffect(() => {
    if (me.error) navigate({ to: "/login" });
  }, [me.error, navigate]);

  const logout = () => {
    memberToken.clear();
    navigate({ to: "/login" });
  };

  return (
    <div className="lifeos-member">
      <div className="content_full">
        <div className="header_content lifeos-banner">
          <Link to="/" aria-label="Life OS home" style={{ display: "block", height: 200 }} />
        </div>
      </div>
      <div className="content_menu">
        <div className="ddsmoothmenu" id="menu">
          <ul>
            {MENU.map((m, i) => (
              <li key={m.label} style={{ zIndex: 100 - i }} onMouseEnter={() => setOpen(i)} onMouseLeave={() => setOpen(null)}>
                <a href="#" onClick={(e) => { e.preventDefault(); setOpen(open === i ? null : i); }}>
                  {m.label} <i className="fa fa-chevron-down" aria-hidden="true" />
                </a>
                <ul style={{ top: 50, width: 200, display: open === i ? "block" : "none", visibility: "visible" }}>
                  {m.items.map((it) => (
                    <li key={it.to}>
                      <Link to={it.to} onClick={() => setOpen(null)}>
                        <i className="fa fa-chevron-right" aria-hidden="true" /> {it.label}
                      </Link>
                    </li>
                  ))}
                  <span />
                </ul>
              </li>
            ))}
            <li>
              <a href="#" onClick={(e) => { e.preventDefault(); logout(); }}>
                Logout <i className="fa fa-lock" aria-hidden="true" />
              </a>
            </li>
          </ul>
          <br style={{ clear: "left" }} />
        </div>
      </div>
      <div id="wrapper">
        <div className="mid_image">
          <div id="main_member">{ready && me.data ? children : <div className="main_bg about"><h2 id="heading">Loading…</h2></div>}</div>
        </div>
      </div>
      <div className="site_scroll_bottom">
        <div className="site_scroll">
          <div className="site_scroll_container">
            <div className="lifeos-marquee">
              <span>Welcome to Life OS member panel{me.data ? `, ${me.data.name}` : ""}.</span>
              <span>Keep your profile and nominee information up to date.</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

export function Page({ title, children, center }: { title: string; children: ReactNode; center?: boolean }) {
  return (
    <div className="main_bg about" {...(center ? { align: "center" } : {})}>
      <h2 id="heading">{title}</h2>
      {children}
    </div>
  );
}

export function DataTable({ head, rows, empty = "No records found" }: { head: string[]; rows: ReactNode[][]; empty?: string }) {
  return (
    <table className="table" width="100%" cellPadding={0} cellSpacing={0} style={{ marginTop: 20 }}>
      <thead>
        <tr>{head.map((h) => <th key={h}>{h}</th>)}</tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <tr><td colSpan={head.length}>{empty}</td></tr>
        ) : (
          rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j}>{c}</td>)}</tr>)
        )}
      </tbody>
    </table>
  );
}

export function Msg({ ok, text }: { ok?: boolean; text: string | null }) {
  if (!text) return null;
  return <div id="message" style={{ color: ok ? "#009541" : "#C00", fontWeight: "bold", margin: "10px 0" }}>{text}</div>;
}

export const fmtDate = (s?: string | null) => (s ? new Date(s.replace(" ", "T")).toLocaleString("en-GB", { day: "2-digit", month: "long", year: "numeric", hour: "numeric", minute: "2-digit" }) : "");
