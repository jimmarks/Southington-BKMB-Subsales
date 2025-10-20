import { createSlice, PayloadAction } from '@reduxjs/toolkit';

interface AuthState {
  isAuthenticated: boolean;
  teamName: string | null;
  code: string | null;
}

const initialState: AuthState = {
  isAuthenticated: false,
  teamName: null,
  code: null,
};

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    login(state, action: PayloadAction<{ teamName: string; code: string }>) {
      state.isAuthenticated = true;
      state.teamName = action.payload.teamName;
      state.code = action.payload.code;
    },
    logout(state) {
      state.isAuthenticated = false;
      state.teamName = null;
      state.code = null;
    },
  },
});

export const { login, logout } = authSlice.actions;

export default authSlice.reducer;