import { Route, Routes } from 'react-router'
import { RequireAuth } from './auth/RequireAuth'
import { Layout } from './components/Layout'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import { BoardPage, GoalsPage, NotFoundPage, TaskDetailPage } from './pages/placeholders'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route index element={<HomePage />} />
          <Route path="board" element={<BoardPage />} />
          <Route path="goals" element={<GoalsPage />} />
          <Route path="tasks" element={<TaskDetailPage />} />
          {/* Numeric ID or task key, e.g. /tasks/TCB-31. */}
          <Route path="tasks/:taskId" element={<TaskDetailPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Route>
    </Routes>
  )
}
